<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Notifications\Models\ScheduledNotification;
use Illuminate\Support\Str;

/**
 * Trimite mesajele programate ajunse la termen. Fiecare rând este „revendicat” atomic (UPDATE condiționat),
 * deci două rulări simultane ale scheduler-ului nu trimit același reminder de două ori.
 */
class ScheduledDispatcher
{
    public function __construct(private readonly Notifier $notifier) {}

    public function run(int $limit = 200): int
    {
        $sent = 0;
        $due = ScheduledNotification::query()->where('status', 'pending')->where('send_at', '<=', now())->orderBy('send_at')->limit($limit)->pluck('id');

        foreach ($due as $id) {
            $claim = (string) Str::uuid();
            $claimed = ScheduledNotification::query()->whereKey($id)->where('status', 'pending')->update(['status' => 'processing', 'claim_token' => $claim, 'claimed_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $item = ScheduledNotification::query()->with('booking.customer', 'booking.package')->find($id);
            $booking = $item->booking;

            $skip = $this->skipReason($item, $booking);
            if ($skip) {
                $item->forceFill(['status' => 'skipped', 'skip_reason' => $skip])->save();

                continue;
            }

            $log = $this->notifier->sendToCustomer($booking->customer, $item->template_key, $item->channel, ['booking' => $booking], 'service', $item->idempotency_key, $booking);
            $item->forceFill(['status' => $log ? 'sent' : 'skipped', 'message_log_id' => $log?->id, 'skip_reason' => $log ? null : 'fără adresă sau șablon'])->save();
            $sent += $log ? 1 : 0;
        }

        return $sent;
    }

    private function skipReason(ScheduledNotification $item, ?Booking $booking): ?string
    {
        if (! $booking || ! $booking->customer) {
            return 'rezervare inexistentă';
        }
        if (str_starts_with($item->template_key, 'booking.reminder')) {
            if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
                return 'rezervarea este '.$booking->statusLabel();
            }
            // reminder generat pentru o oră veche (rezervarea a fost mutată între timp)
            if (! str_ends_with($item->idempotency_key, ':'.($booking->starts_at?->timestamp ?? 0))) {
                return 'oră schimbată';
            }
            if ($booking->starts_at?->isPast()) {
                return 'prea târziu';
            }
        }
        if ($item->template_key === 'booking.post_visit' && ! in_array($booking->status, ['completed', 'checked_in'], true)) {
            return 'vizita nu a avut loc';
        }

        return null;
    }
}
