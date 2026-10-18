<?php

namespace App\Console\Commands;

use App\Domain\Messaging\CampaignService;
use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Messaging\MessageSender;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\MessageRule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Run by cron every minute (D-006): starts due campaigns, plans reminders and sends due messages.
 */
class SendMessages extends Command
{
    protected $signature = 'messages:send {--limit=50}';

    protected $description = 'Plans reminders, starts due campaigns and sends queued messages';

    public function handle(MessageSender $sender, MessageDispatcher $dispatcher, CampaignService $campaigns): int
    {
        $this->planReminders($dispatcher);
        foreach (Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->get() as $campaign) {
            $campaigns->start($campaign);
        }
        $count = $sender->processDue((int) $this->option('limit'));
        $campaigns->closeFinished();
        $this->info("Processed {$count} message(s).");

        return self::SUCCESS;
    }

    /**
     * Reminders for confirmed appointments that enter the reminder window. Appointments booked
     * inside the window already received a confirmation and get no extra reminder.
     */
    private function planReminders(MessageDispatcher $dispatcher): void
    {
        foreach (MessageRule::where('type', 'appointment_reminder')->where('enabled', true)->whereNotNull('offset_hours')->get() as $rule) {
            $hours = (int) $rule->offset_hours;
            Appointment::query()
                ->where('status', AppointmentStatus::Confirmed->value)
                ->when($rule->location_id, fn ($q) => $q->where('location_id', $rule->location_id),
                    // The general rule covers locations without their own rule.
                    fn ($q) => $q->whereNotIn('location_id', MessageRule::where('type', 'appointment_reminder')->whereNotNull('location_id')->pluck('location_id')))
                ->whereBetween('starts_at', [now(), now()->addHours($hours)->addMinutes(10)])
                ->whereRaw('created_at <= DATE_SUB(starts_at, INTERVAL ? HOUR)', [$hours])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('messages')->whereColumn('messages.appointment_id', 'appointments.id')
                    ->where('messages.type', 'appointment_reminder')->whereColumn('messages.context_starts_at', 'appointments.starts_at'))
                ->limit(500)->get()
                ->each(function (Appointment $appointment) use ($dispatcher, $hours) {
                    $sendAt = CarbonImmutable::instance($appointment->starts_at)->subHours($hours);
                    $dispatcher->forAppointment($appointment, 'appointment_reminder', $sendAt->isPast() ? null : $sendAt);
                });
        }
    }
}
