<?php

namespace App\Modules\Display\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cod de activare de unică folosință (se păstrează doar hash-ul), valabil 30 de minute. */
class DisplayPairing extends Model
{
    protected $table = 'display_pairings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(DisplayScreen::class, 'display_screen_id');
    }
}
