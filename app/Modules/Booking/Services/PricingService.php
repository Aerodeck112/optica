<?php

namespace App\Modules\Booking\Services;

use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PriceRule;
use App\Modules\Catalog\Models\VatRate;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Calculul prețului unei rezervări din datele administrate (pachet, reguli de preț, servicii suplimentare).
 * Nu inventează nimic: dacă lipsește un preț, rezultatul este „de stabilit” (priced = false).
 */
class PricingService
{
    /**
     * @param  array<int,int>  $addonQuantities  [addon_id => quantity]
     */
    public function quote(Package $package, ?CarbonInterface $startsAt, int $participants, array $addonQuantities = []): Quote
    {
        $items = [];
        $priced = true;
        $rule = $startsAt ? $this->matchingRule($package, $startsAt) : null;

        $basePrice = $rule?->price_minor ?? $package->price_minor;
        $extraPrice = $rule?->extra_person_price_minor ?? $package->extra_person_price_minor;
        $vat = VatRate::forGroup($package->vat_group ?? 'standard', $startsAt);

        if ($basePrice === null) {
            $priced = false;
        } else {
            switch ($package->pricing_model) {
                case 'flat':
                    $items[] = $this->item('package', $package->id, $package->getTranslation('name', 'ro'), 1, $basePrice, $vat, ($package->vat_group ?? 'standard'));
                    break;
                case 'flat_plus_extra':
                    $items[] = $this->item('package', $package->id, $package->getTranslation('name', 'ro'), 1, $basePrice, $vat, $package->vat_group);
                    $extra = max(0, $participants - (int) $package->included_participants);
                    if ($extra > 0) {
                        if ($extraPrice === null) {
                            $priced = false;
                        } else {
                            $items[] = $this->item('extra_person', $package->id, 'Participant suplimentar', $extra, $extraPrice, $vat, $package->vat_group);
                        }
                    }
                    break;
                default: // per_person
                    $items[] = $this->item('package', $package->id, $package->getTranslation('name', 'ro').' — per participant', $participants, $basePrice, $vat, $package->vat_group);
            }
        }

        $extraMinutes = 0;
        if ($addonQuantities) {
            $addons = Addon::query()->whereIn('id', array_keys($addonQuantities))->where('is_active', true)->get()->keyBy('id');
            foreach ($addonQuantities as $addonId => $quantity) {
                $addon = $addons[$addonId] ?? null;
                if (! $addon || $quantity < 1) {
                    continue;
                }
                $quantity = min($quantity, max(1, (int) $addon->max_quantity));
                $extraMinutes += (int) $addon->extra_minutes * $quantity;
                if ($addon->price_minor === null) {
                    $priced = false;

                    continue;
                }
                $units = $addon->pricing_model === 'per_person' ? $quantity * $participants : $quantity;
                $items[] = $this->item('addon', $addon->id, $addon->getTranslation('name', 'ro'), $units, $addon->price_minor, VatRate::forGroup($addon->vat_group ?? 'standard', $startsAt), $addon->vat_group ?? 'standard');
            }
        }

        $total = $priced ? array_sum(array_column($items, 'total_minor')) : null;
        $deposit = $total === null ? null : $this->deposit($package, $total);

        return new Quote(
            priced: $priced,
            items: $items,
            totalMinor: $total,
            depositMinor: $deposit,
            extraMinutes: $extraMinutes,
            priceRule: $rule?->label,
            currency: $package->currency,
        );
    }

    public function deposit(Package $package, int $totalMinor): int
    {
        return match ($package->deposit_type) {
            'fixed' => min($totalMinor, (int) $package->deposit_value),
            'percent' => Money::percentOf($totalMinor, (int) $package->deposit_value),
            default => 0,
        };
    }

    private function matchingRule(Package $package, CarbonInterface $startsAt): ?PriceRule
    {
        $local = LocalTime::toLocal($startsAt);
        $time = $local->format('H:i:s');
        $date = $local->toDateString();

        return $package->priceRules()
            ->where('is_active', true)
            ->get()
            ->first(function (PriceRule $rule) use ($local, $time, $date) {
                if ($rule->weekdays && ! in_array($local->isoWeekday(), array_map('intval', $rule->weekdays), true)) {
                    return false;
                }
                if ($rule->time_from && $time < $rule->time_from) {
                    return false;
                }
                if ($rule->time_until && $time >= $rule->time_until) {
                    return false;
                }
                if ($rule->valid_from && $date < $rule->valid_from->toDateString()) {
                    return false;
                }
                if ($rule->valid_until && $date > $rule->valid_until->toDateString()) {
                    return false;
                }

                return true;
            });
    }

    private function item(string $type, ?int $referenceId, string $description, int|float $quantity, int $unitPrice, ?VatRate $vat, string $vatGroup): array
    {
        return [
            'type' => $type,
            'reference_id' => $referenceId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price_minor' => $unitPrice,
            'total_minor' => (int) round($unitPrice * $quantity),
            'vat_rate' => $vat?->rate !== null ? (string) $vat->rate : null,
            'vat_group' => $vatGroup,
        ];
    }
}
