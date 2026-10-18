<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

/*
 | One cPanel cron entry runs `php artisan schedule:run` every minute (D-006, DEPLOYMENT.md).
 | No Redis, Supervisor or long-running worker is needed.
 */

// Heartbeat for the diagnostics panel: a missing cron run produces a warning (OPS-02).
Schedule::call(fn () => Setting::updateOrCreate(['key' => 'system.cron_last_run'], ['value' => now()->toIso8601String()]))
    ->name('cron-heartbeat')
    ->everyMinute();

Schedule::command('bookings:expire')->everyMinute()->withoutOverlapping(5);

// Database queue processed in short batches; the lock prevents parallel workers (ARC-02).
Schedule::command('queue:work database --stop-when-empty --max-time=50 --tries=3 --backoff=60')
    ->name('queue-batch')
    ->everyMinute()
    ->withoutOverlapping(5);

// Reminders, campaigns and queued messages (COM-02). Short runs, never two at once.
Schedule::command('messages:send')->everyMinute()->withoutOverlapping(5);
