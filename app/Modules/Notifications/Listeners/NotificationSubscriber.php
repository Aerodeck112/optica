<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Booking\Events\BookingCancelled;
use App\Modules\Booking\Events\BookingConfirmed;
use App\Modules\Booking\Events\BookingCreated;
use App\Modules\Booking\Events\BookingRescheduled;
use App\Modules\Booking\Events\BookingStatusChanged;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Events\LeadCreated;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Notifications\Services\ReminderScheduler;
use Illuminate\Events\Dispatcher;

/**
 * Mesajele de serviciu declanșate de evenimentele rezervărilor și ale cererilor.
 */
class NotificationSubscriber
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly ReminderScheduler $reminders,
        private readonly SettingsRepository $settings,
    ) {}

    public function onCreated(BookingCreated $event): void
    {
        $booking = $event->booking->loadMissing(['customer', 'package']);
        $template = match ($booking->status) {
            'request' => 'booking.request_received',
            'confirmed' => 'booking.confirmed',
            default => 'booking.pending',
        };
        if ($booking->customer) {
            $this->notifier->sendToCustomer($booking->customer, $template, 'email', ['booking' => $booking, 'manage_url' => $event->manageToken ? lroute('booking.manage', ['token' => $event->manageToken], $booking->locale ?: 'ro') : null], 'service', "booking:{$booking->id}:created", $booking);
        }
        $this->reminders->sync($booking);
        $this->staffAlert('staff.new_booking', ['booking' => $booking], "staff:booking:{$booking->id}");
    }

    public function onConfirmed(BookingConfirmed $event): void
    {
        $booking = $event->booking->loadMissing(['customer', 'package']);
        if ($event->notifyCustomer && $booking->customer) {
            $this->notifier->sendToCustomer($booking->customer, 'booking.confirmed', 'email', ['booking' => $booking], 'service', "booking:{$booking->id}:confirmed", $booking);
            if ($this->settings->bool('booking.reminder_sms_enabled')) {
                $this->notifier->sendToCustomer($booking->customer, 'booking.confirmed', 'sms', ['booking' => $booking], 'service', "booking:{$booking->id}:confirmed:sms", $booking);
            }
        }
        $this->reminders->sync($booking);
    }

    public function onRescheduled(BookingRescheduled $event): void
    {
        $booking = $event->booking->loadMissing(['customer', 'package']);
        $this->reminders->sync($booking);
        if ($event->notifyCustomer && $booking->customer) {
            $this->notifier->sendToCustomer($booking->customer, 'booking.rescheduled', 'email', ['booking' => $booking, 'previous_start' => $event->previousStart], 'service', "booking:{$booking->id}:rescheduled:{$booking->starts_at?->timestamp}", $booking);
        }
    }

    public function onCancelled(BookingCancelled $event): void
    {
        $booking = $event->booking->loadMissing(['customer', 'package']);
        $this->reminders->cancelAll($booking, 'rezervare anulată');
        if ($event->notifyCustomer && $booking->customer) {
            $this->notifier->sendToCustomer($booking->customer, 'booking.cancelled', 'email', ['booking' => $booking], 'service', "booking:{$booking->id}:cancelled", $booking);
        }
    }

    public function onStatusChanged(BookingStatusChanged $event): void
    {
        $booking = $event->booking;
        if ($event->to === 'expired') {
            $this->reminders->cancelAll($booking, 'rezervare expirată');
            if ($booking->customer) {
                $this->notifier->sendToCustomer($booking->customer, 'booking.expired', 'email', ['booking' => $booking], 'service', "booking:{$booking->id}:expired", $booking);
            }
        }
        if ($event->to === 'no_show') {
            $this->reminders->cancelAll($booking, 'neprezentare');
        }
        if ($event->to === 'completed') {
            $this->reminders->schedulePostVisit($booking);
        }
    }

    public function onLeadCreated(LeadCreated $event): void
    {
        $lead = $event->lead->loadMissing('customer');
        if ($lead->customer) {
            $this->notifier->sendToCustomer($lead->customer, 'lead.received', 'email', ['lead' => $lead], 'service', "lead:{$lead->id}:received");
        }
        $this->staffAlert('staff.new_lead', ['lead' => $lead], "staff:lead:{$lead->id}");
    }

    private function staffAlert(string $template, array $context, string $key): void
    {
        $address = $this->settings->filled('contact.notifications_staff');
        if ($address) {
            $this->notifier->sendToAddress('email', $address, $template, $context, 'service', $key);
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            BookingCreated::class => 'onCreated',
            BookingConfirmed::class => 'onConfirmed',
            BookingRescheduled::class => 'onRescheduled',
            BookingCancelled::class => 'onCancelled',
            BookingStatusChanged::class => 'onStatusChanged',
            LeadCreated::class => 'onLeadCreated',
        ];
    }
}
