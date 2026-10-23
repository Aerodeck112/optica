<?php

namespace App\Modules\Booking\Services;

use RuntimeException;

/**
 * Eroare de business cu un cod stabil (pentru API/AI) și un mesaj clar pentru utilizator.
 */
class BookingException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function slotUnavailable(): self
    {
        return new self('slot_unavailable', 'Intervalul ales nu mai este disponibil. Alege altă oră.');
    }

    public static function holdExpired(): self
    {
        return new self('hold_expired', 'Rezervarea temporară a orei a expirat, iar ora nu mai este liberă. Alege altă oră.');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self('invalid_transition', "Rezervarea nu poate trece din starea „{$from}” în „{$to}”.");
    }
}
