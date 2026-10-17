<?php

return [
    // Interface and content languages (LNG-01). The first one is the default.
    'locales' => ['ro', 'hu'],

    // Wall-clock timezone of the clinics; the database stores UTC (ARC-08, D-003).
    'clinic_timezone' => env('CLINIC_TIMEZONE', 'Europe/Bucharest'),

    'booking' => [
        // Temporary hold while the public form is completed (PRG-03).
        'hold_minutes' => (int) env('BOOKING_HOLD_MINUTES', 10),
        // How long a pending (unconfirmed) online request keeps the slot blocked (PRG-01).
        'pending_expiry_hours' => (int) env('BOOKING_PENDING_EXPIRY_HOURS', 24),
        // Slot grid used when a schedule segment does not define one.
        'default_slot_minutes' => 15,
        // Default travel time between two different locations, when not configured per pair.
        'default_travel_minutes' => (int) env('BOOKING_DEFAULT_TRAVEL_MINUTES', 30),
        // Calendar auto-refresh interval in seconds (ARC-05).
        'calendar_poll_seconds' => (int) env('CALENDAR_POLL_SECONDS', 15),
    ],

    'security' => [
        // Roles that must use two-factor authentication (SEC-01).
        'two_factor_roles' => ['superadmin', 'operations_admin', 'location_manager', 'finance'],
        'two_factor_enforced' => (bool) env('TWO_FACTOR_ENFORCED', true),
        'login_max_attempts' => 5,
        'login_decay_seconds' => 300,
    ],
];
