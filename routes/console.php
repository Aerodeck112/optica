<?php

use App\Modules\Booking\Services\BookingService;
use App\Modules\Notifications\Services\ScheduledDispatcher;
use Illuminate\Support\Facades\Schedule;

/*
| Pe cPanel, un singur cron rulează în fiecare minut:
|   * * * * * cd /home/USER/aplicatie && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
| Scheduler-ul pornește și worker-ul de coadă pentru scurt timp (nu este nevoie de proces permanent).
*/

Schedule::command('queue:work --stop-when-empty --max-time='.config('aw.queue.worker_max_time', 50).' --tries=6 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->name('queue-worker');

Schedule::call(fn () => app(BookingService::class)->expireDue())->everyMinute()->name('bookings-expire')->withoutOverlapping();
Schedule::call(fn () => app(ScheduledDispatcher::class)->run())->everyMinute()->name('notifications-dispatch')->withoutOverlapping();

Schedule::command('aw:campaigns-dispatch')->everyMinute()->withoutOverlapping()->name('campaigns');
Schedule::command('aw:cms-publish-scheduled')->everyMinute()->name('cms-scheduled');
Schedule::command('aw:efactura-poll')->everyTenMinutes()->withoutOverlapping()->name('efactura-poll');
Schedule::command('aw:efactura-inbox')->hourly()->withoutOverlapping()->name('efactura-inbox');
Schedule::command('aw:fiscal-timeouts')->everyMinute()->name('fiscal-timeouts');
Schedule::command('aw:loyalty-expire')->dailyAt('03:10')->name('loyalty-expire');
Schedule::command('aw:retention')->dailyAt('03:30')->name('retention');
Schedule::command('aw:backup --type=database')->dailyAt('02:15')->name('backup-db');
Schedule::command('aw:backup --type=full')->weeklyOn(1, '02:45')->name('backup-full');
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
