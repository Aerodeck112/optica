<?php

namespace App\Modules\Arena\Models;

use App\Modules\Arena\Services\AvatarCatalog;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profilul unui jucător, distinct de clientul care rezervă (un părinte rezervă pentru copii, un organizator pentru grup).
 * Statisticile folosesc id-ul stabil; schimbarea nickname-ului nu creează alt jucător.
 * Pe ecrane apare doar identitatea publică aprobată: nickname aprobat + avatar, și numai cu acordul de afișare.
 */
class Player extends Model
{
    public const NICKNAME_STATUSES = [
        'missing' => 'Nickname de completat',
        'review' => 'De verificat',
        'approved' => 'Aprobat',
        'blocked' => 'Respins — de completat altul',
    ];

    public const CONSENTS = [
        'unknown' => 'Neîntrebat (nu se afișează)',
        'granted' => 'Acceptă afișarea pe ecrane',
        'refused' => 'Nu acceptă afișarea',
        'withdrawn' => 'Și-a retras acordul',
    ];

    protected $table = 'players';

    protected $guarded = ['id'];

    protected $attributes = ['nickname_status' => 'missing', 'display_consent' => 'unknown', 'is_minor' => false];

    protected $hidden = ['private_name', 'identity_hash', 'display_consent_guardian'];

    protected function casts(): array
    {
        return [
            'private_name' => 'encrypted',
            'display_consent_guardian' => 'encrypted',
            'is_minor' => 'boolean',
            'nickname_reviewed_at' => 'immutable_datetime',
            'display_consent_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(SessionParticipant::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(PlayerWaiver::class);
    }

    public function hasNickname(): bool
    {
        return filled($this->nickname) && in_array($this->nickname_status, ['review', 'approved'], true);
    }

    /** Poate apărea nominal pe ecranele din arenă (nickname aprobat și acord de afișare). */
    public function isPublic(): bool
    {
        return $this->nickname_status === 'approved' && filled($this->nickname) && $this->display_consent === 'granted';
    }

    public function avatarUrl(): string
    {
        return AvatarCatalog::url($this->avatar_key);
    }

    /** Eticheta din CRM: nickname-ul sau marcajul „de completat”, niciodată numele real pe ecrane. */
    public function label(): string
    {
        return $this->nickname ?: '(nickname de completat) #'.$this->id;
    }
}
