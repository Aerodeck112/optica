<?php

namespace App\Listeners;

use App\Domain\Messaging\MessageDispatcher;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentChanged;
use App\Models\Appointment;
use App\Models\Message;
use Throwable;

/**
 * Operational messages after a booking change (COM-04, PRG-06). Runs after the transaction
 * committed; queued reminders of a moved or cancelled appointment are withdrawn at once (and
 * would anyway be skipped by the sender's re-check).
 */
class QueueAppointmentMessages
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function handle(AppointmentChanged $event): void
    {
        try {
            $appointment = Appointment::find($event->appointmentId);
            if (! $appointment) {
                return;
            }
            if (in_array($event->change, ['rescheduled', 'cancelled', 'expired'], true)) {
                Message::where('appointment_id', $appointment->id)->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'status_reason' => 'appointment_'.$event->change, 'updated_at' => now()]);
            }
            $type = match (true) {
                $event->change === 'cancelled' => 'appointment_cancelled',
                $event->change === 'rescheduled' => 'appointment_rescheduled',
                $appointment->status === AppointmentStatus::Pending && $event->change === 'created' => 'booking_received',
                $appointment->status === AppointmentStatus::Confirmed && in_array($event->change, ['created', 'status_changed'], true) => 'appointment_confirmed',
                default => null,
            };
            if ($type) {
                $this->dispatcher->forAppointment($appointment, $type);
            }
        } catch (Throwable $e) {
            // A messaging problem must never undo or block the booking itself.
            report($e);
        }
    }
}
