<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\Quote;
use App\Modules\Catalog\Models\Package;
use App\Modules\Marketing\Models\PromoCode;
use App\Modules\Marketing\Models\PromoCodeRedemption;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Verifică și aplică codurile de reducere. Reducerea se calculează doar din prețul pachetului (nu din opțiuni)
 * și se salvează pe rezervare ca discount_minor, separat de liniile de preț, ca factura să o arate distinct.
 */
class PromoCodeService
{
    /**
     * Verificarea fără client (rezumatul din formular): cod, perioadă, pachet, data sesiunii și valoarea reducerii.
     *
     * @return array{promo: PromoCode, discount_minor: int}
     *
     * @throws BookingException
     */
    public function evaluate(string $code, Package $package, ?CarbonInterface $startsAt, int $participants, Quote $quote): array
    {
        $promo = PromoCode::query()->where('code', PromoCode::normalize($code))->first();
        if (! $promo || ! $promo->isRedeemableOn(LocalTime::now()->toDateString())) {
            throw new BookingException('promo_code', __('site.promo.invalid'));
        }
        if (! $promo->appliesToPackage($package)) {
            throw $this->packageError($promo);
        }
        if ($startsAt && ! $promo->coversSessionDate(LocalTime::toLocal($startsAt)->toDateString())) {
            throw new BookingException('promo_code', __('site.promo.session_dates', ['period' => $this->sessionPeriod($promo)]));
        }
        if (! $quote->priced) {
            throw new BookingException('promo_code', __('site.promo.invalid'));
        }

        $discount = $this->discountFor($promo, $package, $participants, $quote);
        if ($discount <= 0) {
            throw $this->packageError($promo);
        }

        return ['promo' => $promo, 'discount_minor' => min($discount, (int) $quote->totalMinor)];
    }

    /**
     * Rezervă o utilizare a codului. Se apelează în tranzacția care creează rezervarea; rândul codului este blocat,
     * ca două rezervări simultane să nu depășească limitele.
     *
     * @throws BookingException
     */
    public function assertAvailable(PromoCode $promo, ?int $customerId): void
    {
        $locked = PromoCode::query()->lockForUpdate()->findOrFail($promo->id);
        if ($locked->max_redemptions !== null && $locked->activeRedemptions()->count() >= $locked->max_redemptions) {
            throw new BookingException('promo_code', __('site.promo.exhausted'));
        }
        if ($customerId && $locked->max_per_customer !== null
            && $locked->activeRedemptions()->where('customer_id', $customerId)->count() >= $locked->max_per_customer) {
            throw new BookingException('promo_code', __('site.promo.already_used'));
        }
    }

    public function record(PromoCode $promo, Booking $booking, int $discountMinor): PromoCodeRedemption
    {
        return PromoCodeRedemption::query()->create([
            'promo_code_id' => $promo->id,
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'discount_minor' => $discountMinor,
        ]);
    }

    /** Textul liniei de reducere, de exemplu „Cod WELCOME13 — 1 sesiune gratuită”. */
    public function label(PromoCode $promo, ?int $discountMinor = null): string
    {
        $what = match ($promo->discount_type) {
            'free_participants' => trans_choice('site.promo.free_sessions', $promo->discount_value, ['count' => $promo->discount_value]),
            'percent' => '−'.$promo->discount_value.'%',
            default => '−'.Money::format($discountMinor ?? $promo->discount_value),
        };

        return __('site.promo.line', ['code' => $promo->code, 'what' => $what]);
    }

    public function sessionPeriod(PromoCode $promo): string
    {
        $format = fn ($date) => $date->locale(app()->getLocale())->isoFormat('D MMMM');
        if ($promo->session_from && $promo->session_until) {
            return $format($promo->session_from).' – '.$format($promo->session_until);
        }
        if ($promo->session_until) {
            return __('site.promo.until', ['date' => $format($promo->session_until)]);
        }

        return $promo->session_from ? __('site.promo.from', ['date' => $format($promo->session_from)]) : '';
    }

    private function packageError(PromoCode $promo): BookingException
    {
        $names = $promo->packages()->map(fn (Package $p) => $p->getTranslation('name', app()->getLocale()))->implode(', ');

        return new BookingException('promo_code', $names !== '' ? __('site.promo.package', ['packages' => $names]) : __('site.promo.invalid'));
    }

    private function discountFor(PromoCode $promo, Package $package, int $participants, Quote $quote): int
    {
        $packageLines = array_values(array_filter($quote->items, fn (array $item) => in_array($item['type'], ['package', 'extra_person'], true)));
        $packageTotal = (int) array_sum(array_column($packageLines, 'total_minor'));

        return match ($promo->discount_type) {
            'free_participants' => $package->pricing_model === 'per_person' && $packageLines
                ? (int) $packageLines[0]['unit_price_minor'] * min($participants, max(1, $promo->discount_value))
                : 0,
            'percent' => (int) round($packageTotal * min(100, $promo->discount_value) / 100),
            'fixed' => min($packageTotal, $promo->discount_value),
            default => 0,
        };
    }
}
