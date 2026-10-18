<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Crypt;

class Patient extends Model
{
    protected $guarded = ['id', 'cnp_encrypted', 'cnp_hash'];

    protected $hidden = ['cnp_encrypted', 'cnp_hash'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Patient $patient) {
            $patient->phone_normalized = self::normalizePhone($patient->phone);
            $patient->email = $patient->email ? mb_strtolower(trim($patient->email)) : null;
        });
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '40'.substr($digits, 1);
        }

        return $digits ?: null;
    }

    public static function hashCnp(string $cnp): string
    {
        return hash_hmac('sha256', preg_replace('/\D+/', '', $cnp), config('app.key'));
    }

    public function setCnp(?string $cnp): void
    {
        $cnp = $cnp !== null ? preg_replace('/\D+/', '', $cnp) : null;
        $this->cnp_encrypted = $cnp ? Crypt::encryptString($cnp) : null;
        $this->cnp_hash = $cnp ? self::hashCnp($cnp) : null;
    }

    public function cnp(): ?string
    {
        return $this->cnp_encrypted ? Crypt::decryptString($this->cnp_encrypted) : null;
    }

    public function fullName(): string
    {
        return trim($this->last_name.' '.$this->first_name);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function primaryLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'primary_location_id');
    }

    public function legalGuardian(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'legal_guardian_id');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(PatientRelation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'subject');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'merged_into_id');
    }

    public function age(): ?int
    {
        return $this->birth_date?->diffInYears(now());
    }
}
