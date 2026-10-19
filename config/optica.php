<?php

return [
    // Installed version; replaced by every update package (UPD-01).
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0',

    // Null = decided by storage/app/installed.lock; tests and CLI tools may force it (INS-02).
    'installed' => env('OPTICA_INSTALLED') === null ? null : (bool) env('OPTICA_INSTALLED'),

    // Test installation (e.g. test.optica-optofarm.ro): not indexed, marked on every page, and no
    // message reaches a patient; only the addresses and numbers listed here receive messages.
    'test_site' => (bool) env('OPTICA_TEST_SITE', false),
    'test_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPTICA_TEST_RECIPIENTS', ''))))),

    'updates' => [
        // Trusted Ed25519 public keys (base64), in addition to resources/update-keys.txt (D-009).
        'keys_file' => resource_path('update-keys.txt'),
        'public_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('UPDATE_PUBLIC_KEYS', ''))))),
        // Limits against oversized or malicious archives (UPD-03).
        'max_package_bytes' => 300 * 1024 * 1024,
        'max_extracted_bytes' => 900 * 1024 * 1024,
        'max_entries' => 40000,
        // Files applied per step, so each request stays short on shared hosting.
        'apply_batch' => 400,
    ],

    'backups' => [
        // Scheduled backups kept; pre-update and pre-restore backups keep their own last 3.
        'keep' => (int) env('BACKUP_KEEP', 7),
    ],

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

    // Text versions of notices and agreements; bump when the beneficiary changes the wording (SEC-05).
    'consents' => [
        'versions' => [
            'privacy_notice' => env('CONSENT_PRIVACY_VERSION', '2026-10'),
            'marketing' => env('CONSENT_MARKETING_VERSION', '2026-10'),
        ],
    ],
];
