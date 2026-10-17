<?php

namespace App\Domain\Scheduling;

use Carbon\CarbonImmutable;

/** Half-open UTC interval [start, end). */
final class Interval
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $meta = [],
    ) {}

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(self $other): bool
    {
        return $this->start <= $other->start && $other->end <= $this->end;
    }

    public function isEmpty(): bool
    {
        return $this->end <= $this->start;
    }

    public function minutes(): int
    {
        return (int) round(($this->end->getTimestamp() - $this->start->getTimestamp()) / 60);
    }

    /** @return list<self> parts of this interval not covered by $other */
    public function subtract(self $other): array
    {
        if (! $this->overlaps($other)) {
            return [$this];
        }
        $parts = [];
        if ($other->start > $this->start) {
            $parts[] = new self($this->start, $other->start, $this->meta);
        }
        if ($other->end < $this->end) {
            $parts[] = new self($other->end, $this->end, $this->meta);
        }

        return $parts;
    }

    /**
     * @param  list<self>  $intervals
     * @param  list<self>  $removals
     * @return list<self>
     */
    public static function subtractAll(array $intervals, array $removals): array
    {
        foreach ($removals as $removal) {
            $next = [];
            foreach ($intervals as $interval) {
                array_push($next, ...$interval->subtract($removal));
            }
            $intervals = $next;
        }

        return $intervals;
    }
}
