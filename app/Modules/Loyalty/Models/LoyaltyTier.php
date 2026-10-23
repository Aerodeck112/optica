<?php

namespace App\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class LoyaltyTier extends Model
{
    use HasTranslations;

    protected $table = 'loyalty_tiers';

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'benefits' => 'array',
            'earn_multiplier' => 'decimal:2',
        ];
    }
}
