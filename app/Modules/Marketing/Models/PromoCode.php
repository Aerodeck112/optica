<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Catalog\Models\Package;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cod de reducere pentru rezervări: sesiuni gratuite (participanți), procent sau sumă fixă,
 * limitat la anumite pachete, la o perioadă de folosire și la o perioadă a sesiunilor.
 */
class PromoCode extends Model
{
    public const TYPES = [
        'free_participants' => 'Participanți gratuiți (sesiuni gratuite)',
        'percent' => 'Procent din pachet',
        'fixed' => 'Sumă fixă',
    ];

    /** Rezervările în aceste stări nu mai consumă codul. */
    public const RELEASED_STATUSES = ['cancelled', 'expired'];

    protected $table = 'promo_codes';

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true, 'max_per_customer' => 1];

    protected function casts(): array
    {
        return [
            'package_ids' => 'array',
            'redeem_from' => 'immutable_date',
            'redeem_until' => 'immutable_date',
            'session_from' => 'immutable_date',
            'session_until' => 'immutable_date',
            'is_active' => 'boolean',
            'discount_value' => 'integer',
            'max_redemptions' => 'integer',
            'max_per_customer' => 'integer',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /** Utilizările care contează (rezervările anulate sau expirate eliberează codul). */
    public function activeRedemptions(): HasMany
    {
        return $this->redemptions()->whereHas('booking', fn ($q) => $q->whereNotIn('status', self::RELEASED_STATUSES));
    }

    /** @return Collection<int, Package> */
    public function packages(): Collection
    {
        return $this->package_ids ? Package::query()->whereIn('id', $this->package_ids)->get() : new Collection;
    }

    public function appliesToPackage(Package $package): bool
    {
        return empty($this->package_ids) || in_array($package->id, array_map('intval', $this->package_ids), true);
    }

    public function isRedeemableOn(string $localDate): bool
    {
        return $this->is_active
            && ($this->redeem_from === null || $this->redeem_from->toDateString() <= $localDate)
            && ($this->redeem_until === null || $this->redeem_until->toDateString() >= $localDate);
    }

    public function coversSessionDate(string $localDate): bool
    {
        return ($this->session_from === null || $this->session_from->toDateString() <= $localDate)
            && ($this->session_until === null || $this->session_until->toDateString() >= $localDate);
    }

    public static function normalize(?string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    protected function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = self::normalize($value);
    }
}
