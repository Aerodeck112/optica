<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $table = 'integrations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'last_tested_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    protected $hidden = ['credentials'];

    /** Credențialele decriptate. Nu se afișează niciodată în interfață — doar ultimele caractere, mascat. */
    public function decryptedCredentials(): array
    {
        if (! $this->credentials) {
            return [];
        }
        try {
            return json_decode(\Illuminate\Support\Facades\Crypt::decryptString($this->credentials), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function storeCredentials(array $credentials): void
    {
        $credentials = array_filter($credentials, fn ($value) => $value !== null && $value !== '');
        $this->credentials = $credentials ? \Illuminate\Support\Facades\Crypt::encryptString(json_encode($credentials)) : null;
    }

    public function credential(string $key): ?string
    {
        return $this->decryptedCredentials()[$key] ?? null;
    }

    public function isLive(): bool
    {
        return $this->mode === 'live' && $this->status === 'configured';
    }

    public function isUsable(): bool
    {
        return in_array($this->mode, ['live', 'sandbox'], true) && in_array($this->status, ['configured', 'sandbox_tested'], true);
    }
}
