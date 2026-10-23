<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Notifications\Models\ScheduledNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Remindere legate de ora rezervării. La orice schimbare (reprogramare, anulare, expirare) se recalculează:
 * cele vechi se anulează, iar cheia de idempotență include ora de început, deci nu apar duplicate.
 */
class ReminderScheduler
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function sync(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            ScheduledNotification::query()
                ->where('booking_id', $booking->id)
                ->where('status', 'pending')
                ->whereIn('template_key', ['booking.reminder_long', 'booking.reminder_short'])
                ->update(['status' => 'cancelled', 'skip_reason' => 'resincronizare']);

            if (! in_array($booking->status, ['pending', 'confirmed'], true) || ! $booking->starts_at || $booking->starts_at->isPast()) {
                return;
            }

            $channels = ['email'];
            if ($this->settings->bool('booking.reminder_sms_enabled')) {
                $channels[] = 'sms';
            }

            foreach (['booking.reminder_long' => 'booking.reminder_long_hours', 'booking.reminder_short' => 'booking.reminder_short_hours'] as $template => $settingKey) {
                $hours = (int) $this->settings->int($settingKey, 0);
                if ($hours <= 0) {
                    continue;
                }
                $sendAt = $booking->starts_at->subHours($hours);
                if ($sendAt->isPast()) {
                    continue;
                }
                foreach ($channels as $channel) {
                    $this->schedule($booking, $template, $channel, $sendAt);
                }
            }
        });
    }

    public function schedulePostVisit(Booking $booking): void
    {
        $hours = (int) $this->settings->int('booking.post_visit_hours', 0);
        if ($hours <= 0) {
            return;
        }
        $base = $booking->ends_at && $booking->ends_at->isFuture() ? $booking->ends_at : CarbonImmutable::now();
        $this->schedule($booking, 'booking.post_visit', 'email', $base->addHours($hours));
    }

    public function cancelAll(Booking $booking, string $reason): void
    {
        ScheduledNotification::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'skip_reason' => $reason]);
    }

    private function schedule(Booking $booking, string $template, string $channel, CarbonImmutable $sendAt): void
    {
        $key = sprintf('booking:%d:%s:%s:%d', $booking->id, $template, $channel, $booking->starts_at?->timestamp ?? 0);
        ScheduledNotification::query()->updateOrCreate(
            ['idempotency_key' => $key],
            ['booking_id' => $booking->id, 'template_key' => $template, 'channel' => $channel, 'send_at' => $sendAt, 'status' => 'pending', 'skip_reason' => null],
        );
    }
}
