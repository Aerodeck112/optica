<?php

namespace App\Modules\Finance\Efactura;

use App\Modules\Core\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client pentru API-ul RO e-Factura (ANAF). Autorizarea OAuth2 se face o singură dată, din panou, de către
 * reprezentantul firmei, cu certificatul digital (logincert.anaf.ro). Tokenurile se păstrează criptate.
 * Fără autorizare, integrarea rămâne „Neconfigurată” și nu se încearcă nicio transmitere.
 */
class AnafClient
{
    public const OAUTH_BASE = 'https://logincert.anaf.ro/anaf-oauth2/v1';

    public const API_BASE = 'https://api.anaf.ro';

    public const VALIDATE_URL = 'https://webservicesp.anaf.ro/prod/FCTEL/rest/validare/FACT1';

    public function integration(): ?Integration
    {
        return Integration::query()->where('key', 'efactura')->first();
    }

    public function environment(): string
    {
        return ($this->integration()?->options['environment'] ?? 'test') === 'prod' ? 'prod' : 'test';
    }

    public function isAuthorized(): bool
    {
        $integration = $this->integration();

        return $integration && $integration->driver === 'anaf' && $integration->credential('refresh_token') !== null && $integration->mode !== 'disabled' && $integration->status !== 'blocked';
    }

    public function redirectUri(): string
    {
        return route('admin.anaf.callback');
    }

    public function authorizeUrl(string $state): string
    {
        $integration = $this->integration();
        if (! $integration?->credential('client_id')) {
            throw new RuntimeException('Completează mai întâi Client ID și Client secret ale aplicației înregistrate la ANAF.');
        }

        return self::OAUTH_BASE.'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $integration->credential('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'token_content_type' => 'jwt',
        ]);
    }

    public function exchangeCode(string $code): void
    {
        $this->storeTokens($this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri()]));
    }

    private function tokenRequest(array $params): array
    {
        $integration = $this->integration();
        $response = Http::asForm()->timeout(20)
            ->withBasicAuth((string) $integration->credential('client_id'), (string) $integration->credential('client_secret'))
            ->post(self::OAUTH_BASE.'/token', $params + ['token_content_type' => 'jwt']);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('ANAF OAuth a refuzat cererea (HTTP '.$response->status().'): '.Str::limit((string) ($response->json('error_description') ?? $response->body()), 200));
        }

        return $response->json();
    }

    private function storeTokens(array $tokens): void
    {
        $integration = $this->integration();
        $credentials = $integration->decryptedCredentials();
        $credentials['access_token'] = $tokens['access_token'];
        $credentials['refresh_token'] = $tokens['refresh_token'] ?? ($credentials['refresh_token'] ?? null);
        $credentials['access_expires_at'] = (string) (time() + (int) ($tokens['expires_in'] ?? 3600) - 60);
        $integration->storeCredentials($credentials);
        $integration->save();
    }

    public function accessToken(): string
    {
        if (! $this->isAuthorized()) {
            throw new RuntimeException('e-Factura neconfigurată: lipsește autorizarea OAuth ANAF.');
        }
        $integration = $this->integration();
        if ((int) $integration->credential('access_expires_at') > time() && $integration->credential('access_token')) {
            return (string) $integration->credential('access_token');
        }
        $this->storeTokens($this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => (string) $integration->credential('refresh_token')]));

        return (string) $this->integration()->credential('access_token');
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->accessToken())->timeout(60);
    }

    /** @return array{ok: bool, upload_index: ?string, errors: array<int,string>} */
    public function upload(string $xml, string $sellerCif, bool $b2c): array
    {
        $endpoint = $b2c ? 'uploadb2c' : 'upload';
        $response = $this->api()->withBody($xml, 'text/plain')
            ->post(self::API_BASE.'/'.$this->environment().'/FCTEL/rest/'.$endpoint.'?'.http_build_query(['standard' => 'UBL', 'cif' => $sellerCif]));
        if (! $response->successful()) {
            return ['ok' => false, 'upload_index' => null, 'errors' => ['HTTP '.$response->status().': '.Str::limit($response->body(), 300)]];
        }
        $doc = @simplexml_load_string($response->body());
        $index = $doc ? (string) ($doc['index_incarcare'] ?? '') : '';
        $errors = [];
        if ($doc) {
            foreach ($doc->children() as $child) {
                if (isset($child['errorMessage'])) {
                    $errors[] = (string) $child['errorMessage'];
                }
            }
        }

        return ['ok' => $index !== '' && (string) ($doc['ExecutionStatus'] ?? '0') === '0', 'upload_index' => $index ?: null, 'errors' => $errors];
    }

    /** @return array{state: string, download_id: ?string, errors: array<int,string>} state: ok | nok | processing | invalid */
    public function status(string $uploadIndex): array
    {
        $response = $this->api()->get(self::API_BASE.'/'.$this->environment().'/FCTEL/rest/stareMesaj', ['id_incarcare' => $uploadIndex]);
        $doc = @simplexml_load_string($response->body());
        $state = $doc ? strtolower((string) ($doc['stare'] ?? '')) : '';
        $errors = [];
        if ($doc) {
            foreach ($doc->children() as $child) {
                if (isset($child['errorMessage'])) {
                    $errors[] = (string) $child['errorMessage'];
                }
            }
        }

        return [
            'state' => match (true) {
                $state === 'ok' => 'ok',
                $state === 'nok' => 'nok',
                str_contains($state, 'prelucrare') => 'processing',
                str_contains($state, 'erori') => 'invalid',
                default => 'processing',
            },
            'download_id' => $doc && isset($doc['id_descarcare']) ? (string) $doc['id_descarcare'] : null,
            'errors' => $errors,
        ];
    }

    public function download(string $downloadId): string
    {
        $response = $this->api()->get(self::API_BASE.'/'.$this->environment().'/FCTEL/rest/descarcare', ['id' => $downloadId]);
        if (! $response->successful()) {
            throw new RuntimeException('Descărcarea răspunsului ANAF a eșuat (HTTP '.$response->status().').');
        }

        return $response->body();
    }

    /** @return array<int, array<string, mixed>> mesajele SPV din ultimele zile (facturi primite, erori) */
    public function messages(string $sellerCif, int $days = 30): array
    {
        $response = $this->api()->get(self::API_BASE.'/'.$this->environment().'/FCTEL/rest/listaMesajeFactura', ['zile' => max(1, min(60, $days)), 'cif' => $sellerCif]);

        return (array) ($response->json('mesaje') ?? []);
    }

    /** Validare prin serviciul public ANAF (fără autorizare). @return array{ok: bool, errors: array<int,string>} */
    public function validateRemote(string $xml): array
    {
        $response = Http::timeout(30)->withBody($xml, 'text/plain')->post(self::VALIDATE_URL);
        if (! $response->successful()) {
            return ['ok' => false, 'errors' => ['Serviciul de validare ANAF a răspuns HTTP '.$response->status()]];
        }

        return ['ok' => $response->json('stare') === 'ok', 'errors' => array_map(fn ($m) => (string) ($m['message'] ?? $m), (array) ($response->json('Messages') ?? []))];
    }

    /** Pentru testul de conexiune din panou. */
    public function connectionStatus(Integration $integration): array
    {
        if (! $integration->credential('client_id') || ! $integration->credential('client_secret')) {
            return ['ok' => false, 'message' => 'Neconfigurată: lipsesc Client ID / Client secret.'];
        }
        if (! $integration->credential('refresh_token')) {
            return ['ok' => false, 'message' => 'Neconfigurată: autorizează aplicația la ANAF (butonul „Autorizează la ANAF”).'];
        }
        try {
            $this->accessToken();

            return ['ok' => true, 'message' => 'Autorizare ANAF validă (mediu '.$this->environment().').'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
