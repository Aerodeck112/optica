<?php

/*
 * Configurație de infrastructură pentru Another World Cluj.
 * Datele comerciale (program, prețuri, date firmă) NU stau aici — se administrează din panou (tabela settings).
 */
return [
    'timezone' => 'Europe/Bucharest',

    // Limbile vizibile public. O limbă se adaugă doar după ce traducerile sunt complete și verificate.
    'public_locales' => array_filter(explode(',', (string) env('AW_PUBLIC_LOCALES', 'ro'))),

    // staging: noindex global + banner „Mediu de test”
    'is_staging' => (bool) env('AW_STAGING', false),

    // În afara producției, mesajele nu pleacă la clienți reali. Se trimit doar la aceste adrese/numere de test.
    'messaging' => [
        'force_sandbox' => env('AW_MESSAGING_SANDBOX', env('APP_ENV') !== 'production'),
        'test_recipients_email' => array_filter(explode(',', (string) env('AW_TEST_EMAILS', ''))),
        'test_recipients_sms' => array_filter(explode(',', (string) env('AW_TEST_PHONES', ''))),
        'max_attempts' => 5,
    ],

    'queue' => [
        // worker-ul pornit de scheduler pe hosting fără proces permanent
        'worker_max_time' => (int) env('AW_QUEUE_MAX_TIME', 50),
    ],

    'ai' => [
        // modelul implicit; poate fi schimbat din Integrări → Asistent virtual (câmpul „Model”)
        'model' => env('AW_AI_MODEL', 'claude-opus-5-5'),
        'effort' => env('AW_AI_EFFORT', 'medium'), // low | medium | high — adâncimea raționamentului
        // include gândirea internă a modelului; răspunsurile vizibile sunt scurte
        'max_output_tokens' => (int) env('AW_AI_MAX_OUTPUT_TOKENS', 8000),
        'max_tool_rounds' => 6,
        'max_turns_per_conversation' => 40,
        'summary_ttl_minutes' => 15,
        'timeout_seconds' => 60,
        // prețuri în micro-USD per 1M tokeni (pentru plafonul zilnic de cost); actualizați la schimbarea tarifelor
        'pricing' => [
            'claude-opus-5-5' => ['input' => 4_000_000, 'output' => 20_000_000, 'cache_read' => 200_000, 'cache_write' => 5_000_000],
            'claude-sonnet-5-5' => ['input' => 2_000_000, 'output' => 10_000_000, 'cache_read' => 200_000, 'cache_write' => 2_500_000],
            'claude-haiku-5-5' => ['input' => 100_000, 'output' => 500_000, 'cache_read' => 10_000, 'cache_write' => 125_000],
        ],
    ],

    'backup' => [
        'path' => storage_path('app/private/backups'),
        'keep_daily' => 14,
        'remote_disk' => env('AW_BACKUP_REMOTE_DISK'), // disc Flysystem configurat în filesystems.php (s3/sftp/ftp)
    ],

    'fiscal_agent' => [
        'dispatch_timeout_seconds' => 90, // după acest interval fără răspuns, bonul devine „incert”
    ],
];
