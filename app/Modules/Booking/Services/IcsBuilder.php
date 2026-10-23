<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Core\Settings\SettingsRepository;

/**
 * Fișier calendar (RFC 5545) pentru o rezervare. Orele sunt în UTC, deci calendarul clientului le afișează corect
 * indiferent de schimbarea orei.
 */
class IcsBuilder
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function forBooking(Booking $booking): string
    {
        $start = $booking->starts_at->utc()->format('Ymd\THis\Z');
        $end = ($booking->ends_at ?? $booking->starts_at->addHour())->utc()->format('Ymd\THis\Z');
        $location = trim(implode(', ', array_filter([
            $this->settings->filled('venue.address_street'),
            $this->settings->filled('venue.address_city') ?? 'Cluj-Napoca',
        ])));
        $summary = 'Another World Cluj — '.($booking->package?->getTranslation('name', $booking->locale ?: 'ro') ?? 'Rezervare');
        $description = 'Cod rezervare: '.$booking->code.'\nParticipanți: '.$booking->participants;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Another World Cluj//Rezervari//RO',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$booking->code.'@'.parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start,
            'DTEND:'.$end,
            'SUMMARY:'.$this->escape($summary),
            'DESCRIPTION:'.$this->escape($description, false),
            'LOCATION:'.$this->escape($location),
            'STATUS:'.($booking->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'SEQUENCE:'.(int) $booking->reschedule_count,
            'BEGIN:VALARM',
            'TRIGGER:-PT2H',
            'ACTION:DISPLAY',
            'DESCRIPTION:'.$this->escape($summary),
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n";
    }

    private function escape(string $value, bool $escapeNewlines = true): string
    {
        $value = str_replace(['\\', ';', ','], ['\\\\', '\;', '\,'], $value);

        return $escapeNewlines ? str_replace(["\r\n", "\n"], '\n', $value) : $value;
    }

    /** Liniile mai lungi de 75 de octeți se pliază (RFC 5545 §3.1). */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > 74) {
                $out .= $current."\r\n ";
                $current = '';
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
