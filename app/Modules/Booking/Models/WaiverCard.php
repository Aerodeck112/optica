<?php

namespace App\Modules\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Card fizic refolosibil pentru tabletă: serie (AWC-007), cod fix de 6 cifre și cod de bare.
 * Cardul e „la un grup” cât timp are un cod activ în waiver_codes; altfel e liber, la recepție.
 */
class WaiverCard extends Model
{
    protected $table = 'waiver_cards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WaiverCode::class, 'waiver_card_id');
    }

    public function currentAssignment(): ?WaiverCode
    {
        return $this->assignments()->active()->with('booking')->latest('id')->first();
    }

    public function label(): string
    {
        return 'Card '.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }
}
