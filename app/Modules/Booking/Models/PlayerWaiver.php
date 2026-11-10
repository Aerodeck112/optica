<?php

namespace App\Modules\Booking\Models;

use App\Models\User;
use App\Modules\Arena\Models\Player;
use App\Modules\Cms\Models\LegalDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Declarația de participare semnată de un jucător (sau de părinte, pentru un minor).
 * Păstrează versiunea și amprenta textului acceptat; datele personale sensibile sunt criptate.
 * Operatorii de joc văd doar numărul declarațiilor; datele le vede doar cine are „waivers.view”.
 */
class PlayerWaiver extends Model
{
    protected $table = 'player_waivers';

    protected $guarded = ['id'];

    protected $hidden = ['signature', 'participant_birth_date', 'guardian_phone', 'email'];

    protected function casts(): array
    {
        return [
            'participant_birth_date' => 'encrypted',
            'guardian_phone' => 'encrypted',
            'email' => 'encrypted',
            'signature' => 'encrypted',
            'is_minor' => 'boolean',
            'marketing_email' => 'boolean',
            'photo_consent' => 'boolean',
            'screen_display' => 'boolean',
            'signed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
