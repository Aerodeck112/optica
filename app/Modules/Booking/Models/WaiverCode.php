<?php

namespace App\Modules\Booking\Models;

use App\Modules\Arena\Models\GameSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Codul de 6 cifre cu care un jucător își semnează declarația pe tableta de la recepție.
 * Se folosește o singură dată și expiră; după semnare rămâne legat de declarație, pentru dovadă.
 */
class WaiverCode extends Model
{
    protected $table = 'waiver_codes';

    protected $guarded = ['id'];

    protected $hidden = ['code', 'code_hash'];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(WaiverCard::class, 'waiver_card_id');
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(PlayerWaiver::class, 'player_waiver_id');
    }

    /** @param  Builder<WaiverCode>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** Codul afișat cu un spațiu la mijloc („482 913”), mai ușor de citit și de tastat. */
    public function formatted(): string
    {
        return substr((string) $this->code, 0, 3).' '.substr((string) $this->code, 3);
    }
}
