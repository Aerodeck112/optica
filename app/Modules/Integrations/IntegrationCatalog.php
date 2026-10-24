<?php

namespace App\Modules\Integrations;

/**
 * Integrările externe și câmpurile lor. Credențialele se păstrează criptate (APP_KEY) în tabela integrations
 * și nu sunt trimise niciodată înapoi în browser — formularul arată doar dacă un câmp este setat.
 */
final class IntegrationCatalog
{
    public const STATUSES = [
        'unconfigured' => 'Neconfigurat',
        'adapter_ready' => 'Adaptor pregătit — credențiale introduse, netestat',
        'sandbox_tested' => 'Testat în sandbox',
        'configured' => 'Configurat — test de conexiune reușit (live)',
        'production_verified' => 'Implementat și verificat în producție',
        'blocked' => 'Blocat',
    ];

    public const MODES = ['disabled' => 'Dezactivat', 'sandbox' => 'Sandbox / test', 'live' => 'Producție'];

    /** @return array<string, array{label:string, help:string, drivers: array<string, array{label:string, credentials: array<string, array{label:string, secret?:bool}>, options?: array<string, array{label:string, default?:mixed}>}>}> */
    public static function definitions(): array
    {
        return [
            'email' => [
                'label' => 'E-mail tranzacțional și marketing',
                'help' => 'Confirmări, remindere, campanii. În mediile de dezvoltare/test mesajele rămân în sandbox, cu excepția adreselor de test.',
                'drivers' => [
                    'smtp' => ['label' => 'SMTP (ex. cont de e-mail din cPanel)', 'credentials' => [
                        'host' => ['label' => 'Server SMTP'], 'port' => ['label' => 'Port (587 / 465)'], 'username' => ['label' => 'Utilizator'],
                        'password' => ['label' => 'Parolă', 'secret' => true], 'encryption' => ['label' => 'Criptare (tls / ssl)'],
                    ], 'options' => ['from_email' => ['label' => 'Adresă expeditor'], 'from_name' => ['label' => 'Nume expeditor', 'default' => 'Another World Cluj']]],
                    'brevo' => ['label' => 'Brevo (API, cu rapoarte de livrare)', 'credentials' => [
                        'api_key' => ['label' => 'Cheie API', 'secret' => true], 'webhook_secret' => ['label' => 'Secret webhook (parametrul ?key=)', 'secret' => true],
                    ], 'options' => ['from_email' => ['label' => 'Adresă expeditor (domeniu verificat)'], 'from_name' => ['label' => 'Nume expeditor', 'default' => 'Another World Cluj']]],
                ],
            ],
            'sms' => [
                'label' => 'SMS',
                'help' => 'Remindere și campanii SMS. Costul se estimează înainte de trimiterea campaniilor și respectă plafonul setat.',
                'drivers' => [
                    'twilio' => ['label' => 'Twilio', 'credentials' => [
                        'account_sid' => ['label' => 'Account SID'], 'auth_token' => ['label' => 'Auth token', 'secret' => true],
                    ], 'options' => ['from' => ['label' => 'Expeditor (număr sau Messaging Service SID)']]],
                ],
            ],
            'payments' => [
                'label' => 'Plăți online (avansuri, vouchere)',
                'help' => 'Datele cardului sunt introduse doar pe pagina procesatorului; aplicația nu le vede și nu le stochează.',
                'drivers' => [
                    'stripe' => ['label' => 'Stripe Checkout', 'credentials' => [
                        'secret_key' => ['label' => 'Cheie secretă (sk_…)', 'secret' => true], 'webhook_secret' => ['label' => 'Secret semnătură webhook (whsec_…)', 'secret' => true],
                    ], 'options' => []],
                ],
            ],
            'ai' => [
                'label' => 'Asistent virtual (Claude)',
                'help' => 'Cheia API rămâne pe server. Bugetul zilnic și limitele se setează în Setări → Asistent AI.',
                'drivers' => [
                    'anthropic' => ['label' => 'Anthropic Claude API', 'credentials' => ['api_key' => ['label' => 'Cheie API', 'secret' => true]],
                        'options' => ['model' => ['label' => 'Model', 'default' => 'claude-sonnet-5-5']]],
                ],
            ],
            'efactura' => [
                'label' => 'RO e-Factura (ANAF SPV)',
                'help' => 'Necesită aplicație înregistrată în portalul ANAF și autorizare OAuth cu certificatul digital al reprezentantului firmei.',
                'drivers' => [
                    'anaf' => ['label' => 'ANAF — API e-Factura (OAuth2)', 'credentials' => [
                        'client_id' => ['label' => 'Client ID (aplicația înregistrată la ANAF)'], 'client_secret' => ['label' => 'Client secret', 'secret' => true],
                    ], 'options' => ['environment' => ['label' => 'Mediu (test / prod)', 'default' => 'test']]],
                ],
            ],
            'fiscal' => [
                'label' => 'Casă de marcat / imprimantă fiscală',
                'help' => 'Comunicarea se face printr-un agent local instalat lângă casa de marcat. Dispozitivele și cheile agentului se administrează în Financiar → Case de marcat.',
                'drivers' => [
                    'agent' => ['label' => 'Agent local (polling HTTPS)', 'credentials' => [], 'options' => []],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::definitions()[$key] ?? null;
    }
}
