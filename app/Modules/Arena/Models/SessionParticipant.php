<?php

namespace App\Modules\Arena\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participarea unui jucător la o sesiune. Perechea (sesiune, jucător) este unică: un jucător nu primește
 * credit de două ori pentru aceeași sesiune. Se numără în clasamentul de fidelitate doar participările „active”
 * din sesiuni finalizate, care nu sunt de test și nu au fost excluse printr-o corecție.
 */
class SessionParticipant extends Model
{
    public const STATUSES = [
        'registered' => 'Înregistrat',
        'active' => 'Activ în sesiune',
        'removed' => 'Scos din sesiune',
        'no_show' => 'Nu s-a prezentat',
    ];

    protected $table = 'session_participants';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'registered', 'counts' => true];

    protected function casts(): array
    {
        return [
            'activated_at' => 'immutable_datetime',
            'counts' => 'boolean',
            'score' => 'integer',
            'time_ms' => 'integer',
            'place' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** „Explorator 03”: aliasul afișat pentru cine nu a acceptat afișarea publică. */
    public function alias(): string
    {
        return __('display.alias', ['n' => str_pad((string) $this->slot, 2, '0', STR_PAD_LEFT)]);
    }
}
