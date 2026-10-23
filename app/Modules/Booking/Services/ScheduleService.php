<?php

namespace App\Modules\Booking\Services;

use App\Modules\Core\Models\OpeningHour;
use App\Modules\Core\Models\ScheduleException;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Programul de funcționare pentru o zi locală, cu excepții (zile închise, program special).
 */
class ScheduleService
{
    private ?array $weekly = null;

    /**
     * @return array{open: CarbonImmutable, close: CarbonImmutable, note: ?string}|null  momente UTC; null = închis / neconfigurat
     */
    public function windowFor(string $localDate): ?array
    {
        $exception = ScheduleException::query()->whereDate('date', $localDate)->first();

        if ($exception) {
            if ($exception->is_closed || ! $exception->opens_at || ! $exception->closes_at) {
                return null;
            }

            return $this->build($localDate, $exception->opens_at, $exception->closes_at, $exception->getTranslation('public_note', app()->getLocale(), true) ?: null);
        }

        $weekday = (int) CarbonImmutable::parse($localDate, LocalTime::zone())->isoWeekday();
        $row = $this->weekly()[$weekday] ?? null;

        if (! $row || $row['is_closed'] || ! $row['opens_at'] || ! $row['closes_at']) {
            return null;
        }

        return $this->build($localDate, $row['opens_at'], $row['closes_at'], null);
    }

    public function isConfigured(): bool
    {
        return collect($this->weekly())->contains(fn ($row) => ! $row['is_closed'] && $row['opens_at'] && $row['closes_at']);
    }

    /** Programul săptămânal pentru afișare (doar dacă este configurat). */
    public function weeklyForDisplay(): array
    {
        $days = [1 => 'Luni', 2 => 'Marți', 3 => 'Miercuri', 4 => 'Joi', 5 => 'Vineri', 6 => 'Sâmbătă', 7 => 'Duminică'];
        $out = [];
        foreach ($days as $number => $label) {
            $row = $this->weekly()[$number] ?? null;
            $out[] = [
                'weekday' => $number,
                'label' => __('days.'.$number) !== 'days.'.$number ? __('days.'.$number) : $label,
                'closed' => ! $row || $row['is_closed'] || ! $row['opens_at'],
                'opens' => $row ? substr((string) $row['opens_at'], 0, 5) : null,
                'closes' => $row ? substr((string) $row['closes_at'], 0, 5) : null,
            ];
        }

        return $out;
    }

    private function build(string $date, string $opens, string $closes, ?string $note): ?array
    {
        $open = LocalTime::fromLocal($date, substr($opens, 0, 5));
        // închiderea după miezul nopții (ex: 01:00) aparține zilei următoare
        $closeDate = substr($closes, 0, 5) <= substr($opens, 0, 5)
            ? CarbonImmutable::parse($date)->addDay()->toDateString()
            : $date;
        $close = LocalTime::fromLocal($closeDate, substr($closes, 0, 5));

        if (! $open || ! $close || $close->lessThanOrEqualTo($open)) {
            return null;
        }

        return ['open' => $open, 'close' => $close, 'note' => $note];
    }

    private function weekly(): array
    {
        return $this->weekly ??= OpeningHour::query()->get()->keyBy('weekday')->map(fn ($row) => [
            'is_closed' => (bool) $row->is_closed,
            'opens_at' => $row->opens_at,
            'closes_at' => $row->closes_at,
        ])->all();
    }
}
