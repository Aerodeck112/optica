<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

/**
 * Combinable filters (RAP-02). Dates are calendar days in the clinic's time zone; the date basis
 * (consultation or creation) is always explicit.
 */
final class ReportFilters
{
    public const BASES = ['consultation', 'created'];

    public const COMPARE = ['none', 'previous', 'year'];

    public const PATIENT_TYPES = ['all', 'new', 'returning'];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $basis = 'consultation',
        public readonly string $compare = 'previous',
        public readonly array $locationIds = [],
        public readonly array $specialistIds = [],
        public readonly array $serviceIds = [],
        public readonly array $statuses = [],
        public readonly array $sources = [],
        public readonly string $patientType = 'all',
        public readonly array $creators = [],
        public readonly array $locales = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $tz = config('optica.clinic_timezone');
        $date = fn ($v, $fallback) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::parse($v, $tz) : $fallback;
        $from = $date($data['from'] ?? null, CarbonImmutable::now($tz)->startOfMonth())->startOfDay();
        $to = $date($data['to'] ?? null, CarbonImmutable::now($tz))->endOfDay();
        if ($to < $from) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }
        // At most two years per report keeps the pages fast on shared hosting.
        if ($from->diffInDays($to) > 731) {
            $from = $to->subDays(731)->startOfDay();
        }
        $ints = fn ($v) => array_values(array_unique(array_map('intval', array_filter((array) $v, 'is_numeric'))));
        $strings = fn ($v) => array_values(array_unique(array_filter((array) $v, 'is_string')));

        return new self(
            $from, $to,
            in_array($data['basis'] ?? '', self::BASES, true) ? $data['basis'] : 'consultation',
            in_array($data['compare'] ?? '', self::COMPARE, true) ? $data['compare'] : 'previous',
            $ints($data['locations'] ?? []), $ints($data['specialists'] ?? []), $ints($data['services'] ?? []),
            $strings($data['statuses'] ?? []), $strings($data['sources'] ?? []),
            in_array($data['patient_type'] ?? '', self::PATIENT_TYPES, true) ? $data['patient_type'] : 'all',
            $strings($data['creators'] ?? []), $strings($data['locales'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'basis' => $this->basis, 'compare' => $this->compare,
            'locations' => $this->locationIds, 'specialists' => $this->specialistIds, 'services' => $this->serviceIds, 'statuses' => $this->statuses,
            'sources' => $this->sources, 'patient_type' => $this->patientType, 'creators' => $this->creators, 'locales' => $this->locales,
        ];
    }

    /** The same filters on the comparison period, or null. */
    public function comparison(): ?self
    {
        if ($this->compare === 'none') {
            return null;
        }
        $days = (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
        [$from, $to] = $this->compare === 'year'
            ? [$this->from->subYear(), $this->to->subYear()]
            : [$this->from->subDays($days), $this->from->subDay()->endOfDay()];

        return new self($from, $to, $this->basis, 'none', $this->locationIds, $this->specialistIds, $this->serviceIds, $this->statuses, $this->sources, $this->patientType, $this->creators, $this->locales);
    }

    public function utcRange(): array
    {
        return [$this->from->utc(), $this->to->utc()];
    }
}
