<?php

namespace App\Modules\Booking\Services;

final class Quote
{
    public function __construct(
        public readonly bool $priced,
        public readonly array $items,
        public readonly ?int $totalMinor,
        public readonly ?int $depositMinor,
        public readonly int $extraMinutes,
        public readonly ?string $priceRule,
        public readonly string $currency = 'RON',
        public readonly int $discountMinor = 0,
        public readonly ?string $discountLabel = null,
    ) {}

    /** Aceeași ofertă cu o reducere: totalul liniilor rămâne același, avansul nu depășește suma de plată. */
    public function withDiscount(int $discountMinor, string $label): self
    {
        $due = $this->totalMinor === null ? null : max(0, $this->totalMinor - $discountMinor);

        return new self($this->priced, $this->items, $this->totalMinor, $this->depositMinor === null || $due === null ? $this->depositMinor : min($this->depositMinor, $due),
            $this->extraMinutes, $this->priceRule, $this->currency, $discountMinor, $label);
    }

    /** Suma de plată după reducere. */
    public function dueMinor(): ?int
    {
        return $this->totalMinor === null ? null : max(0, $this->totalMinor - $this->discountMinor);
    }

    public function toArray(): array
    {
        return [
            'priced' => $this->priced,
            'items' => $this->items,
            'total_minor' => $this->totalMinor,
            'deposit_minor' => $this->depositMinor,
            'extra_minutes' => $this->extraMinutes,
            'price_rule' => $this->priceRule,
            'currency' => $this->currency,
            'discount_minor' => $this->discountMinor,
            'discount_label' => $this->discountLabel,
        ];
    }
}
