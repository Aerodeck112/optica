<?php

namespace App\Domain\Messaging;

/**
 * Message types and the variables each one may use (COM-04, TRD-05). Operational messages never
 * carry diagnoses or clinical details: no clinical variable exists.
 */
final class MessageTypes
{
    private const APPOINTMENT_VARS = ['patient_name', 'date', 'time', 'location', 'location_address', 'location_phone', 'specialist', 'service', 'instructions', 'manage_link'];

    public const TYPES = [
        'booking_received' => ['context' => 'appointment', 'vars' => self::APPOINTMENT_VARS],
        'appointment_confirmed' => ['context' => 'appointment', 'vars' => self::APPOINTMENT_VARS],
        'appointment_rescheduled' => ['context' => 'appointment', 'vars' => self::APPOINTMENT_VARS],
        'appointment_cancelled' => ['context' => 'appointment', 'vars' => ['patient_name', 'date', 'time', 'location', 'location_phone', 'booking_link']],
        'appointment_reminder' => ['context' => 'appointment', 'vars' => self::APPOINTMENT_VARS, 'offset' => true],
        'order_ready' => ['context' => 'order', 'vars' => ['patient_name', 'order_number', 'location', 'location_address', 'location_phone']],
        'recall' => ['context' => 'patient', 'vars' => ['patient_name', 'location', 'location_phone', 'booking_link']],
        'marketing' => ['context' => 'campaign', 'vars' => ['patient_name', 'booking_link', 'unsubscribe_link']],
    ];

    /** Operational types configured by rules (marketing goes through campaigns). */
    public static function operational(): array
    {
        return array_values(array_diff(array_keys(self::TYPES), ['marketing']));
    }

    /** @return list<string> */
    public static function variables(string $type): array
    {
        return self::TYPES[$type]['vars'] ?? [];
    }

    /** Fictitious values for previews (TRD-05): never real patient data. */
    public static function sample(string $locale): array
    {
        return [
            'patient_name' => $locale === 'hu' ? 'Minta Anna' : 'Ana Exemplu',
            'date' => '24.11.2026',
            'time' => '10:30',
            'location' => $locale === 'hu' ? 'Minta rendelő' : 'Locație exemplu',
            'location_address' => $locale === 'hu' ? 'Minta utca 1.' : 'Strada Exemplu nr. 1',
            'location_phone' => '0700 000 000',
            'specialist' => 'Dr. Exemplu',
            'service' => $locale === 'hu' ? 'Szemészeti vizsgálat' : 'Consultație oftalmologică',
            'instructions' => $locale === 'hu' ? 'Hozza magával jelenlegi szemüvegét.' : 'Aduceți ochelarii pe care îi purtați.',
            'manage_link' => url('/programare/gestionare/exemplu'),
            'booking_link' => url('/programare'),
            'order_number' => 'C2026-000123',
            'unsubscribe_link' => url('/dezabonare/exemplu'),
        ];
    }
}
