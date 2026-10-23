<?php

namespace App\Modules\Catalog\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class VatRate extends Model
{
    protected $table = 'vat_rates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'needs_accountant_review' => 'boolean',
        ];
    }

    /** Cota aplicabilă unui grup (standard/redus/scutit) la o dată. */
    public static function forGroup(string $group, ?CarbonInterface $on = null): ?self
    {
        $date = ($on ?? now())->toDateString();

        return static::query()
            ->where('group', $group)
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->orderByDesc('valid_from')
            ->first();
    }
}
