<?php

namespace App\Domain\Scheduling;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentChanged;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\Offering;
use App\Models\Resource;
use App\Models\Specialist;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * All writes that occupy calendar time go through here (D-004, PRG-02…PRG-04).
 *
 * Every write runs in a transaction that locks the specialist row (and the candidate resources),
 * then re-checks availability with the same rules used to list slots.
 */
class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly ConflictChecker $conflicts,
    ) {}

    /**
     * @param  array{
     *     starts_at: CarbonImmutable,
     *     source: AppointmentSource,
     *     status?: AppointmentStatus,
     *     patient_id?: ?int,
     *     contact_name?: ?string,
     *     contact_phone?: ?string,
     *     contact_email?: ?string,
     *     locale?: string,
     *     admin_notes?: ?string,
     *     idempotency_key?: ?string,
     *     allow_outside_schedule?: bool,
     *     hold_until?: ?CarbonImmutable,
     *     user?: ?User,
     * }  $data
     */
    public function book(Offering $offering, array $data): Appointment
    {
        $key = $data['idempotency_key'] ?? null;
        if ($key && ($existing = Appointment::where('idempotency_key', $key)->first())) {
            return $existing;
        }

        $source = $data['source'];
        $status = $data['status'] ?? ($source->isOnline() ? AppointmentStatus::Pending : AppointmentStatus::Confirmed);
        $user = $data['user'] ?? null;

        try {
            return DB::transaction(function () use ($offering, $data, $source, $status, $user, $key) {
                $offering = $this->lockOffering($offering);
                [$interval, $block] = $this->intervals($offering, $data['starts_at']);

                $resourceId = $this->assertBookable(
                    $offering, $interval, $block,
                    online: $source->isOnline(),
                    allowOutsideSchedule: (bool) ($data['allow_outside_schedule'] ?? false),
                );

                $appointment = Appointment::create([
                    'uuid' => (string) Str::uuid(),
                    'location_id' => $offering->location_id,
                    'specialist_id' => $offering->specialist_id,
                    'service_id' => $offering->service_id,
                    'offering_id' => $offering->id,
                    'resource_id' => $resourceId,
                    'patient_id' => $data['patient_id'] ?? null,
                    'starts_at' => $interval->start,
                    'ends_at' => $interval->end,
                    'block_starts_at' => $block->start,
                    'block_ends_at' => $block->end,
                    'status' => $status,
                    'source' => $source,
                    'locale' => $data['locale'] ?? app()->getLocale(),
                    'contact_name' => $data['contact_name'] ?? null,
                    'contact_phone' => $data['contact_phone'] ?? null,
                    'contact_email' => $data['contact_email'] ?? null,
                    'admin_notes' => $data['admin_notes'] ?? null,
                    'hold_expires_at' => $data['hold_until'] ?? $this->defaultHoldExpiry($status),
                    'confirmed_at' => $status === AppointmentStatus::Confirmed ? now() : null,
                    'idempotency_key' => $key,
                    'lock_version' => 1,
                    'created_by' => $user?->id,
                    'updated_by' => $user?->id,
                ]);

                $this->record($appointment, 'created', null, $status, user: $user, actor: $user ? 'user' : 'patient');
                $this->dispatchAfterCommit($appointment, 'created');

                return $appointment;
            });
        } catch (QueryException $e) {
            // Two identical submissions raced past the first lookup: return the winner (PRG-03).
            if ($key && $this->isUniqueViolation($e) && ($existing = Appointment::where('idempotency_key', $key)->first())) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Moves an appointment atomically: the new interval is reserved and the old one released
     * in the same transaction (PRG-04). Optionally changes the offering (service/specialist/location).
     */
    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, ?Offering $newOffering = null, ?User $user = null, ?int $expectedVersion = null, bool $allowOutsideSchedule = false): Appointment
    {
        return DB::transaction(function () use ($appointment, $startsAt, $newOffering, $user, $expectedVersion, $allowOutsideSchedule) {
            $current = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($current, $expectedVersion);
            if ($current->status->isFinal()) {
                throw new BookingException(BookingException::INVALID_TRANSITION);
            }

            $offering = $newOffering ?? $current->offering ?? throw new BookingException(BookingException::OFFERING_INACTIVE);
            // Lock both specialists (old and new) in id order to avoid deadlocks.
            $this->lockSpecialists([$current->specialist_id, $offering->specialist_id]);
            $offering = Offering::with('service', 'location')->findOrFail($offering->id);

            [$interval, $block] = $this->intervals($offering, $startsAt);
            $resourceId = $this->assertBookable(
                $offering, $interval, $block,
                online: $current->source->isOnline() && $user === null,
                allowOutsideSchedule: $allowOutsideSchedule,
                excludeId: $current->id,
            );

            $oldStart = $current->starts_at;
            $current->fill([
                'location_id' => $offering->location_id,
                'specialist_id' => $offering->specialist_id,
                'service_id' => $offering->service_id,
                'offering_id' => $offering->id,
                'resource_id' => $resourceId,
                'starts_at' => $interval->start,
                'ends_at' => $interval->end,
                'block_starts_at' => $block->start,
                'block_ends_at' => $block->end,
                'reschedule_count' => $current->reschedule_count + 1,
                'lock_version' => $current->lock_version + 1,
                'updated_by' => $user?->id,
            ])->save();

            $this->record($current, 'rescheduled', $current->status, $current->status, $oldStart, $interval->start, $user, $user ? 'user' : 'patient');
            $this->dispatchAfterCommit($current, 'rescheduled');

            return $current;
        });
    }

    public function cancel(Appointment $appointment, string $reason, ?User $user = null, ?string $note = null, ?int $expectedVersion = null): Appointment
    {
        return $this->transition($appointment, AppointmentStatus::Cancelled, $user, $expectedVersion, [
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
            'cancel_note' => $note,
            'cancelled_by' => $user?->id,
        ], $user ? 'user' : 'patient');
    }

    public function changeStatus(Appointment $appointment, AppointmentStatus $to, ?User $user = null, ?int $expectedVersion = null): Appointment
    {
        if ($to === AppointmentStatus::Cancelled) {
            throw new BookingException(BookingException::INVALID_TRANSITION);
        }

        // Re-activating a cancelled/no-show slot or confirming a hold must re-check availability (PRG-03).
        $recheck = in_array($to, [AppointmentStatus::Confirmed, AppointmentStatus::Pending], true);

        return $this->transition($appointment, $to, $user, $expectedVersion, $to === AppointmentStatus::Confirmed ? ['confirmed_at' => now(), 'hold_expires_at' => null] : [], $user ? 'user' : 'system', $recheck);
    }

    /**
     * Turns a temporary hold into a real request once the patient submits the form.
     */
    public function confirmHold(Appointment $hold, array $details, AppointmentStatus $status = AppointmentStatus::Pending): Appointment
    {
        return DB::transaction(function () use ($hold, $details, $status) {
            $current = Appointment::whereKey($hold->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== AppointmentStatus::Held) {
                return $current;
            }

            $offering = $this->lockOffering($current->offering);
            $block = new Interval(CarbonImmutable::instance($current->block_starts_at), CarbonImmutable::instance($current->block_ends_at));
            $interval = new Interval(CarbonImmutable::instance($current->starts_at), CarbonImmutable::instance($current->ends_at));
            $this->assertBookable($offering, $interval, $block, online: true, allowOutsideSchedule: false, excludeId: $current->id);

            $current->fill($details + [
                'status' => $status,
                'hold_expires_at' => $this->defaultHoldExpiry($status),
                'confirmed_at' => $status === AppointmentStatus::Confirmed ? now() : null,
                'lock_version' => $current->lock_version + 1,
            ])->save();

            $this->record($current, 'status_changed', AppointmentStatus::Held, $status, actor: 'patient');
            $this->dispatchAfterCommit($current, 'created');

            return $current;
        });
    }

    /** Releases a temporary hold immediately (the visitor picked another slot or left the form). */
    public function releaseHold(Appointment $hold): void
    {
        DB::transaction(function () use ($hold) {
            $current = Appointment::whereKey($hold->id)->lockForUpdate()->first();
            if (! $current || $current->status !== AppointmentStatus::Held) {
                return;
            }
            $current->update(['status' => AppointmentStatus::Expired, 'lock_version' => $current->lock_version + 1]);
            $this->record($current, 'expired', AppointmentStatus::Held, AppointmentStatus::Expired, actor: 'patient');
        });
    }

    /** Releases holds and pending requests whose time ran out (PRG-01, PRG-03). */
    public function expireStale(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        Appointment::query()
            ->whereIn('status', [AppointmentStatus::Held->value, AppointmentStatus::Pending->value])
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(200, function ($appointments) use (&$count) {
                foreach ($appointments as $appointment) {
                    DB::transaction(function () use ($appointment, &$count) {
                        $current = Appointment::whereKey($appointment->id)->lockForUpdate()->first();
                        if (! $current || ! in_array($current->status, [AppointmentStatus::Held, AppointmentStatus::Pending], true)) {
                            return;
                        }
                        $from = $current->status;
                        $current->update(['status' => AppointmentStatus::Expired, 'lock_version' => $current->lock_version + 1]);
                        $this->record($current, 'expired', $from, AppointmentStatus::Expired, actor: 'system');
                        $this->dispatchAfterCommit($current, 'expired');
                        $count++;
                    });
                }
            });

        return $count;
    }

    private function transition(Appointment $appointment, AppointmentStatus $to, ?User $user, ?int $expectedVersion, array $extra, string $actor, bool $recheck = false): Appointment
    {
        return DB::transaction(function () use ($appointment, $to, $user, $expectedVersion, $extra, $actor, $recheck) {
            $this->lockSpecialists([$appointment->specialist_id]);
            $current = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($current, $expectedVersion);

            $from = $current->status;
            if (! $from->canTransitionTo($to)) {
                throw new BookingException(BookingException::INVALID_TRANSITION);
            }

            $wasBlocking = in_array($from, AppointmentStatus::blocking(), true)
                && ($current->hold_expires_at === null || $current->hold_expires_at > now());
            if ($recheck && ! $wasBlocking && $current->offering) {
                $offering = Offering::with('service', 'location')->findOrFail($current->offering_id);
                $block = new Interval(CarbonImmutable::instance($current->block_starts_at), CarbonImmutable::instance($current->block_ends_at));
                $interval = new Interval(CarbonImmutable::instance($current->starts_at), CarbonImmutable::instance($current->ends_at));
                $this->assertBookable($offering, $interval, $block, online: false, allowOutsideSchedule: true, excludeId: $current->id);
            }

            $current->fill($extra + [
                'status' => $to,
                'lock_version' => $current->lock_version + 1,
                'updated_by' => $user?->id,
            ])->save();

            $this->record($current, $to === AppointmentStatus::Cancelled ? 'cancelled' : 'status_changed', $from, $to, user: $user, actor: $actor);
            $this->dispatchAfterCommit($current, $to === AppointmentStatus::Cancelled ? 'cancelled' : 'status_changed');

            return $current;
        });
    }

    /**
     * @return int|null resource assigned to the appointment
     */
    private function assertBookable(Offering $offering, Interval $interval, Interval $block, bool $online, bool $allowOutsideSchedule, ?int $excludeId = null): ?int
    {
        if (! $offering->is_active || ! $offering->service->is_active) {
            throw new BookingException(BookingException::OFFERING_INACTIVE);
        }

        if ($online) {
            $link = $this->availability->onlineLink($offering);
            if ($link === null) {
                throw new BookingException(BookingException::ONLINE_DISABLED);
            }
            $now = CarbonImmutable::now();
            if ($interval->start < $now->addMinutes((int) $link->min_lead_minutes)) {
                throw new BookingException(BookingException::TOO_SOON);
            }
            if ($interval->start > $now->addDays((int) $link->max_advance_days)) {
                throw new BookingException(BookingException::TOO_FAR);
            }
        }

        if (! $allowOutsideSchedule && ! $this->availability->fitsSchedule($offering, $interval, $online)) {
            throw new BookingException(BookingException::OUTSIDE_SCHEDULE);
        }

        $existing = $this->conflicts->loadContext($offering, $block->start, $block->end, $excludeId);
        $preferredResource = $this->availability->windowResource($offering, $interval, $online);
        if ($preferredResource === null && $offering->resource_type) {
            $this->lockResources($offering);
        } elseif ($preferredResource !== null) {
            Resource::whereKey($preferredResource)->lockForUpdate()->get();
        }

        $result = $this->conflicts->check($offering, $block, $existing, $preferredResource);
        if (! $result['ok']) {
            throw new BookingException($result['reason']);
        }

        return $result['resource_id'];
    }

    /** @return array{0: Interval, 1: Interval} appointment interval and blocking interval (with buffers) */
    private function intervals(Offering $offering, CarbonImmutable $startsAt): array
    {
        $start = $startsAt->utc()->startOfMinute();
        $end = $start->addMinutes($offering->durationMinutes());

        return [
            new Interval($start, $end),
            new Interval($start->subMinutes($offering->bufferBefore()), $end->addMinutes($offering->bufferAfter())),
        ];
    }

    private function lockOffering(Offering $offering): Offering
    {
        $this->lockSpecialists([$offering->specialist_id]);

        return Offering::with('service', 'location', 'specialist')->findOrFail($offering->id);
    }

    private function lockSpecialists(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        Specialist::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function lockResources(Offering $offering): void
    {
        Resource::query()
            ->where('location_id', $offering->location_id)
            ->where('type', $offering->resource_type)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function assertVersion(Appointment $appointment, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $appointment->lock_version !== $expectedVersion) {
            throw new BookingException(BookingException::CONCURRENT_MODIFICATION);
        }
    }

    private function defaultHoldExpiry(AppointmentStatus $status): ?CarbonImmutable
    {
        return match ($status) {
            AppointmentStatus::Held => CarbonImmutable::now()->addMinutes((int) config('optica.booking.hold_minutes')),
            AppointmentStatus::Pending => CarbonImmutable::now()->addHours((int) config('optica.booking.pending_expiry_hours')),
            default => null,
        };
    }

    private function record(Appointment $appointment, string $type, ?AppointmentStatus $from, ?AppointmentStatus $to, $oldStart = null, $newStart = null, ?User $user = null, string $actor = 'user'): void
    {
        AppointmentEvent::create([
            'appointment_id' => $appointment->id,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'old_starts_at' => $oldStart,
            'new_starts_at' => $newStart,
            'user_id' => $user?->id,
            'actor' => $actor,
            'created_at' => now(),
        ]);
    }

    /** Notifications (reminders etc.) are created only after the transaction commits (PRG-06). */
    private function dispatchAfterCommit(Appointment $appointment, string $change): void
    {
        DB::afterCommit(fn () => event(new AppointmentChanged($appointment->id, $change)));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->errorInfo[1] ?? null, [1062, 1586], true) || ($e->errorInfo[0] ?? null) === '23000';
    }
}
