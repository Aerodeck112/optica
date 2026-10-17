<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    // Technical: temporary reservation while the public form is being completed.
    case Held = 'held';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case InConsultation = 'in_consultation';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    // Technical: a hold or pending request that was not acted on in time.
    case Expired = 'expired';

    /** Statuses that occupy time in the calendar. Held/pending also need an unexpired hold. */
    public static function blocking(): array
    {
        return [
            self::Held, self::Pending, self::Confirmed, self::Arrived,
            self::InConsultation, self::Completed, self::NoShow,
        ];
    }

    public static function blockingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::blocking());
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::NoShow, self::Expired], true);
    }

    /** Allowed manual transitions (CAL-03). */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Held => [self::Pending, self::Confirmed, self::Expired, self::Cancelled],
            self::Pending => [self::Confirmed, self::Cancelled, self::Expired],
            self::Confirmed => [self::Arrived, self::Cancelled, self::NoShow, self::Pending],
            self::Arrived => [self::InConsultation, self::Completed, self::Confirmed],
            self::InConsultation => [self::Completed, self::Arrived],
            self::NoShow => [self::Confirmed],
            self::Completed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function label(?string $locale = null): string
    {
        return __('appointments.status.'.$this->value, [], $locale);
    }
}
