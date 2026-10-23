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
    ) {}

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
        ];
    }
}
