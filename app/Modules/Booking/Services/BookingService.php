<?php

namespace App\Modules\Booking\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Events\BookingCancelled;
use App\Modules\Booking\Events\BookingConfirmed;
use App\Modules\Booking\Events\BookingCreated;
use App\Modules\Booking\Events\BookingRescheduled;
use App\Modules\Booking\Events\BookingStatusChanged;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Models\BookingResource;
use App\Modules\Booking\Models\Resource;
use App\Modules\Booking\Models\ResourceBlock;
use App\Modules\Booking\Models\SlotHold;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Services\ConsentService;
use App\Modules\Crm\Services\CustomerResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Motorul unic de rezervări. Site, asistent AI, recepție și administrare folosesc aceleași metode.
 */
class BookingService
{
    private const CODE_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    public function __construct(
        private readonly ResourceLocker $locker,
        private readonly ConflictChecker $conflicts,
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly SettingsRepository $settings,
        private readonly CustomerResolver $customers,
        private readonly ConsentService $consents,
        private readonly AuditLogger $audit,
    ) {}

    // ——————————————————————————— Blocare temporară ———————————————————————————

    /**
     * Ține slotul cât timp vizitatorul completează datele. Întoarce token-ul în clar o singură dată.
     *
     * @return array{hold: SlotHold, token: string}
     */
    public function hold(Package $package, CarbonImmutable $startsAt, int $participants, array $addons = [], string $source = 'web'): array
    {
        $this->assertBookablePackage($package, $participants);
        $quote = $this->pricing->quote($package, $startsAt, $participants, $addons);

        if ($this->availability->isAvailable($package, $startsAt, $participants, $quote->extraMinutes) === null) {
            throw BookingException::slotUnavailable();
        }

        [$occupiedStart, $occupiedEnd] = $this->occupiedWindow($package, $startsAt, $quote->extraMinutes);
        $token = Str::random(48);
        $minutes = max(5, (int) $this->settings->int('booking.hold_minutes', 15));

        foreach ($this->availability->eligibleResources($package) as $resource) {
            $hold = $this->locker->run($resource->conflictFamilyIds(), function () use ($resource, $package, $occupiedStart, $occupiedEnd, $participants, $token, $minutes, $source) {
                if ($this->conflicts->check($resource, $occupiedStart, $occupiedEnd, $participants, $package->session_mode !== 'shared') !== null) {
                    return null;
                }

                return SlotHold::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'resource_id' => $resource->id,
                    'package_id' => $package->id,
                    'starts_at' => $occupiedStart,
                    'ends_at' => $occupiedEnd,
                    'participants' => $participants,
                    'is_exclusive' => $package->session_mode !== 'shared',
                    'token_hash' => hash('sha256', $token),
                    'source' => $source,
                    'expires_at' => now()->addMinutes($minutes),
                ]);
            });

            if ($hold) {
                return ['hold' => $hold, 'token' => $token];
            }
        }

        throw BookingException::slotUnavailable();
    }

    public function releaseHold(string $publicId, string $token): void
    {
        SlotHold::query()
            ->where('public_id', $publicId)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    // ——————————————————————————— Creare ———————————————————————————

    /**
     * @return array{booking: Booking, manage_token: string, checkin_token: string}
     */
    public function create(BookingData $data): array
    {
        $package = $data->package;
        $this->assertParticipants($package, $data->participants);

        if (! $data->isStaff()) {
            $this->assertRequiredPolicies($data);
        }

        $quote = $this->pricing->quote($package, $data->startsAt, $data->participants, $data->addons);
        $instant = ! $data->forceRequest && $data->startsAt !== null && $this->canBookInstantly($package, $data);

        $manageToken = Str::random(48);
        $checkinToken = Str::random(32);

        $result = $instant
            ? $this->createInstant($data, $quote, $manageToken, $checkinToken)
            : $this->createRequest($data, $quote, $manageToken, $checkinToken);

        $result = $result->fresh();
        BookingCreated::dispatch($result, $manageToken);

        return ['booking' => $result, 'manage_token' => $manageToken, 'checkin_token' => $checkinToken];
    }

    private function createInstant(BookingData $data, Quote $quote, string $manageToken, string $checkinToken): Booking
    {
        $package = $data->package;
        [$occupiedStart, $occupiedEnd] = $this->occupiedWindow($package, $data->startsAt, $quote->extraMinutes);
        $exclusive = $package->session_mode !== 'shared';

        $hold = null;
        if ($data->holdPublicId) {
            $hold = SlotHold::query()->where('public_id', $data->holdPublicId)->first();
            if (! $hold || ! $data->holdToken || ! hash_equals($hold->token_hash, hash('sha256', $data->holdToken)) || $hold->released_at !== null) {
                $hold = null;
            }
        }

        // Fără hold valid, un vizitator poate rezerva doar un slot real din program (aliniat, în orar, cu termenul minim respectat).
        if (! $data->isStaff() && ! $hold && $this->availability->isAvailable($package, $data->startsAt, $data->participants, $quote->extraMinutes) === null) {
            throw BookingException::slotUnavailable();
        }

        $candidates = $hold
            ? [Resource::query()->findOrFail($hold->resource_id)]
            : ($data->resourceId ? [Resource::query()->findOrFail($data->resourceId)] : $this->availability->eligibleResources($package, $data->isStaff()));

        foreach ($candidates as $resource) {
            $booking = $this->locker->run($resource->conflictFamilyIds(), function () use ($data, $quote, $resource, $occupiedStart, $occupiedEnd, $exclusive, $hold, $manageToken, $checkinToken) {
                if (! $data->isStaff() && $data->startsAt->lessThan(now())) {
                    return null;
                }
                if ($this->conflicts->check($resource, $occupiedStart, $occupiedEnd, $data->participants, $exclusive, null, $hold?->id) !== null) {
                    return null;
                }

                [$status, $expiresAt] = $this->initialStatus($data, $quote);
                $booking = $this->persistBooking($data, $quote, $status, $expiresAt, $manageToken, $checkinToken);

                BookingResource::query()->create([
                    'booking_id' => $booking->id,
                    'resource_id' => $resource->id,
                    'starts_at' => $occupiedStart,
                    'ends_at' => $occupiedEnd,
                    'participants' => $data->participants,
                    'is_exclusive' => $exclusive,
                    'is_active' => true,
                ]);

                if ($hold) {
                    $hold->forceFill(['released_at' => now(), 'booking_id' => $booking->id])->save();
                }

                return $booking;
            });

            if ($booking) {
                return $booking;
            }
        }

        throw $hold && $hold->expires_at->isPast() ? BookingException::holdExpired() : BookingException::slotUnavailable();
    }

    private function createRequest(BookingData $data, Quote $quote, string $manageToken, string $checkinToken): Booking
    {
        return DB::transaction(fn () => $this->persistBooking($data, $quote, 'request', null, $manageToken, $checkinToken));
    }

    private function persistBooking(BookingData $data, Quote $quote, string $status, ?CarbonImmutable $expiresAt, string $manageToken, string $checkinToken): Booking
    {
        $package = $data->package;
        $customer = $this->customers->resolve([
            'name' => $data->contactName,
            'email' => $data->contactEmail,
            'phone' => $data->contactPhone,
            'company_name' => $data->companyName,
            'locale' => $data->locale,
        ], $data->source);

        $endsAt = $data->startsAt?->addMinutes((int) $package->slot_minutes + $quote->extraMinutes);

        $booking = null;
        for ($attempt = 0; $attempt < 5 && $booking === null; $attempt++) {
            try {
                $booking = DB::transaction(fn () => Booking::query()->create([
                    'code' => $this->generateCode(),
                    'package_id' => $package->id,
                    'game_id' => $data->gameId,
                    'customer_id' => $customer->id,
                    'starts_at' => $data->startsAt,
                    'ends_at' => $endsAt,
                    'participants' => $data->participants,
                    'age_groups' => $data->ageGroups ?: null,
                    'status' => $status,
                    'payment_status' => 'unpaid',
                    'invoice_status' => 'not_invoiced',
                    'source' => $data->source,
                    'payment_method' => $data->paymentMethod,
                    'contact_name' => $data->contactName,
                    'contact_email' => $data->contactEmail,
                    'contact_phone' => $data->contactPhone,
                    'company_name' => $data->companyName,
                    'customer_notes' => $data->customerNotes,
                    'internal_notes' => $data->internalNotes,
                    'created_by' => $data->createdBy,
                    'currency' => $package->currency,
                    'total_minor' => $quote->totalMinor,
                    'deposit_minor' => $quote->depositMinor ?? 0,
                    'price_snapshot' => $quote->toArray() + ['package' => ['id' => $package->id, 'name' => $package->getTranslation('name', 'ro'), 'slot_minutes' => $package->slot_minutes, 'play_minutes' => $package->play_minutes]],
                    'accepted_policies' => $this->policyRecords($data),
                    'manage_token_hash' => hash('sha256', $manageToken),
                    'checkin_token_hash' => hash('sha256', $checkinToken),
                    'expires_at' => $expiresAt,
                    'confirmed_at' => $status === 'confirmed' ? now() : null,
                    'locale' => $data->locale,
                    'lead_id' => $data->leadId,
                    'analytics_client_ref' => $data->analyticsClientRef,
                ]));
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'bookings_code_unique')) {
                    throw $e;
                }
            }
        }

        foreach ($quote->items as $item) {
            $booking->items()->create($item);
        }

        $this->recordEvent($booking, 'created', null, $status, $data->createdBy ? 'user' : ($data->source === 'ai' ? 'ai' : 'customer'), $data->createdBy, [
            'source' => $data->source,
            'mode' => $status === 'request' ? 'request' : 'instant',
        ]);

        $customer->forceFill([
            'bookings_count' => $customer->bookings()->count(),
            'first_booking_at' => $customer->first_booking_at ?? now(),
            'last_booking_at' => now(),
        ])->save();

        foreach (['email', 'sms'] as $channel) {
            if (! empty($data->marketingConsent[$channel])) {
                $this->consents->grant($customer, $channel, 'booking_form', $data->consentContext + ['booking_code' => $booking->code]);
            }
        }

        return $booking;
    }

    // ——————————————————————————— Tranziții ———————————————————————————

    /** Confirmă o rezervare în așteptare sau transformă o cerere în rezervare (cu verificarea disponibilității). */
    public function confirm(Booking $booking, ?int $userId = null, bool $notify = true, ?int $resourceId = null): Booking
    {
        if (! $booking->canTransitionTo('confirmed')) {
            throw BookingException::invalidTransition($booking->status, 'confirmed');
        }

        if ($booking->status === 'request') {
            $this->occupyForRequest($booking, $resourceId);
        }

        $from = $booking->status;
        DB::transaction(function () use ($booking, $userId, $from) {
            $booking->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'expires_at' => null])->save();
            $this->recordEvent($booking, 'status_changed', $from, 'confirmed', $userId ? 'user' : 'system', $userId);
        });

        BookingConfirmed::dispatch($booking->fresh(), $notify);
        BookingStatusChanged::dispatch($booking, $from, 'confirmed');

        return $booking;
    }

    private function occupyForRequest(Booking $booking, ?int $resourceId): void
    {
        $package = $booking->package;
        if (! $booking->starts_at || ! $package || ! $package->slot_minutes) {
            throw new BookingException('missing_schedule', 'Stabiliți data, ora și un pachet cu durată configurată înainte de confirmare.');
        }

        $extra = (int) ($booking->price_snapshot['extra_minutes'] ?? 0);
        [$occupiedStart, $occupiedEnd] = $this->occupiedWindow($package, $booking->starts_at, $extra);
        $candidates = $resourceId ? [Resource::query()->findOrFail($resourceId)] : $this->availability->eligibleResources($package, true);

        foreach ($candidates as $resource) {
            $ok = $this->locker->run($resource->conflictFamilyIds(), function () use ($booking, $resource, $occupiedStart, $occupiedEnd, $package) {
                $exclusive = $package->session_mode !== 'shared';
                if ($this->conflicts->check($resource, $occupiedStart, $occupiedEnd, $booking->participants, $exclusive, $booking->id) !== null) {
                    return false;
                }
                BookingResource::query()->create([
                    'booking_id' => $booking->id,
                    'resource_id' => $resource->id,
                    'starts_at' => $occupiedStart,
                    'ends_at' => $occupiedEnd,
                    'participants' => $booking->participants,
                    'is_exclusive' => $exclusive,
                    'is_active' => true,
                ]);

                return true;
            });
            if ($ok) {
                return;
            }
        }

        throw BookingException::slotUnavailable();
    }

    /**
     * Mută rezervarea la altă oră. Verifică disponibilitatea sub blocare, excluzând rezervarea însăși.
     * Notificarea clientului este decisă explicit de cel care face modificarea.
     */
    public function reschedule(Booking $booking, CarbonImmutable $newStart, string $actorType, ?int $actorId = null, bool $notify = true, ?int $resourceId = null): Booking
    {
        if (! $booking->isOpen()) {
            throw new BookingException('not_reschedulable', 'Rezervarea nu mai poate fi reprogramată în starea actuală.');
        }

        $package = $booking->package;
        $previous = $booking->starts_at;

        if ($booking->status === 'request') {
            $booking->forceFill(['starts_at' => $newStart, 'ends_at' => $newStart->addMinutes((int) $package?->slot_minutes)])->save();
            $this->recordEvent($booking, 'rescheduled', null, null, $actorType, $actorId, ['from' => $previous?->toIso8601String(), 'to' => $newStart->toIso8601String()]);

            return $booking;
        }

        $extra = (int) ($booking->price_snapshot['extra_minutes'] ?? 0);
        [$occupiedStart, $occupiedEnd] = $this->occupiedWindow($package, $newStart, $extra);
        $current = $booking->occupancies()->where('is_active', true)->first();
        $candidates = $resourceId
            ? [Resource::query()->findOrFail($resourceId)]
            : array_values(array_filter(array_merge(
                $current ? [Resource::query()->find($current->resource_id)] : [],
                $this->availability->eligibleResources($package, $actorType === 'user'),
            )));

        foreach ($candidates as $resource) {
            $family = array_unique(array_merge($resource->conflictFamilyIds(), $current ? Resource::query()->find($current->resource_id)?->conflictFamilyIds() ?? [] : []));

            $done = $this->locker->run($family, function () use ($booking, $resource, $occupiedStart, $occupiedEnd, $package, $newStart, $extra) {
                $exclusive = $package->session_mode !== 'shared';
                if ($this->conflicts->check($resource, $occupiedStart, $occupiedEnd, $booking->participants, $exclusive, $booking->id) !== null) {
                    return false;
                }
                $booking->occupancies()->where('is_active', true)->update(['is_active' => false]);
                BookingResource::query()->create([
                    'booking_id' => $booking->id,
                    'resource_id' => $resource->id,
                    'starts_at' => $occupiedStart,
                    'ends_at' => $occupiedEnd,
                    'participants' => $booking->participants,
                    'is_exclusive' => $exclusive,
                    'is_active' => true,
                ]);
                $booking->forceFill([
                    'starts_at' => $newStart,
                    'ends_at' => $newStart->addMinutes((int) $package->slot_minutes + $extra),
                    'reschedule_count' => $booking->reschedule_count + 1,
                ])->save();

                return true;
            });

            if ($done) {
                $this->recordEvent($booking, 'rescheduled', null, null, $actorType, $actorId, [
                    'from' => $previous?->toIso8601String(),
                    'to' => $newStart->toIso8601String(),
                    'resource_id' => $resource->id,
                    'notify' => $notify,
                ]);
                BookingRescheduled::dispatch($booking->fresh(), $previous, $notify);

                return $booking->fresh();
            }
        }

        throw BookingException::slotUnavailable();
    }

    public function cancel(Booking $booking, string $reason, string $actorType, ?int $actorId = null, bool $notify = true): Booking
    {
        if (! $booking->canTransitionTo('cancelled')) {
            throw BookingException::invalidTransition($booking->status, 'cancelled');
        }

        $from = $booking->status;
        DB::transaction(function () use ($booking, $reason, $actorType, $actorId, $from) {
            $booking->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => mb_substr($reason, 0, 255),
                'cancelled_by' => $actorType === 'user' ? 'staff' : $actorType,
                'expires_at' => null,
            ])->save();
            $booking->occupancies()->update(['is_active' => false]);
            $this->recordEvent($booking, 'status_changed', $from, 'cancelled', $actorType, $actorId, ['reason' => $reason]);
        });

        $this->audit->log('booking.cancelled', $booking, ['from' => $from, 'reason' => $reason]);
        BookingCancelled::dispatch($booking->fresh(), $notify);
        BookingStatusChanged::dispatch($booking, $from, 'cancelled');

        return $booking;
    }

    /** check-in, finalizare, neprezentare */
    public function transition(Booking $booking, string $to, string $actorType = 'user', ?int $actorId = null): Booking
    {
        if (! in_array($to, ['checked_in', 'completed', 'no_show'], true) || ! $booking->canTransitionTo($to)) {
            throw BookingException::invalidTransition($booking->status, $to);
        }

        $from = $booking->status;
        DB::transaction(function () use ($booking, $to, $actorType, $actorId, $from) {
            $booking->forceFill(array_filter([
                'status' => $to,
                'checked_in_at' => $to === 'checked_in' ? now() : null,
                'completed_at' => $to === 'completed' ? now() : null,
            ]))->save();
            if ($to === 'no_show') {
                $booking->occupancies()->where('ends_at', '>', now())->update(['is_active' => false]);
            }
            $this->recordEvent($booking, 'status_changed', $from, $to, $actorType, $actorId);
        });

        if (in_array($to, ['checked_in', 'completed'], true) && $booking->customer) {
            $booking->customer->forceFill([
                'visits_count' => $booking->customer->bookings()->whereIn('status', ['checked_in', 'completed'])->count(),
                'last_visit_at' => now(),
            ])->save();
        }

        BookingStatusChanged::dispatch($booking->fresh(), $from, $to);

        return $booking;
    }

    public function checkInByToken(string $token, int $userId): Booking
    {
        $booking = Booking::query()->where('checkin_token_hash', hash('sha256', $token))->firstOrFail();

        return $this->transition($booking, 'checked_in', 'user', $userId);
    }

    /** Expiră rezervările în așteptare cu termenul depășit și eliberează slotul. Rulat de scheduler. */
    public function expireDue(): int
    {
        $count = 0;
        Booking::query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$count) {
                foreach ($bookings as $booking) {
                    $updated = DB::transaction(function () use ($booking) {
                        $fresh = Booking::query()->lockForUpdate()->find($booking->id);
                        if ($fresh->status !== 'pending' || ! $fresh->expires_at?->isPast() || in_array($fresh->payment_status, ['deposit_paid', 'paid', 'partially_paid'], true)) {
                            return false;
                        }
                        $fresh->forceFill(['status' => 'expired'])->save();
                        $fresh->occupancies()->update(['is_active' => false]);
                        $this->recordEvent($fresh, 'status_changed', 'pending', 'expired', 'system');

                        return true;
                    });
                    if ($updated) {
                        $count++;
                        BookingStatusChanged::dispatch($booking->fresh(), 'pending', 'expired');
                    }
                }
            });

        SlotHold::query()->whereNull('released_at')->where('expires_at', '<', now()->subDay())->delete();

        return $count;
    }

    // ——————————————————————————— Blocări interne ———————————————————————————

    /**
     * Blochează o resursă (mentenanță, eveniment intern). Pentru serii recurente, toate aparițiile se creează
     * doar dacă niciuna nu intră în conflict cu rezervări existente.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     */
    public function blockResource(Resource $resource, array $intervals, string $type, ?string $reason, ?int $userId): array
    {
        return $this->locker->run($resource->conflictFamilyIds(), function () use ($resource, $intervals, $type, $reason, $userId) {
            $conflicts = [];
            foreach ($intervals as [$start, $end]) {
                $conflict = $this->conflicts->check($resource, $start, $end, 1, true);
                if ($conflict !== null) {
                    $conflicts[] = [$start->toIso8601String(), $conflict];
                }
            }
            if ($conflicts) {
                throw new BookingException('block_conflict', 'Intervalele se suprapun cu rezervări sau blocări existente: '.implode(', ', array_column($conflicts, 0)));
            }
            $series = count($intervals) > 1 ? (string) Str::uuid() : null;

            return array_map(fn ($interval) => ResourceBlock::query()->create([
                'resource_id' => $resource->id,
                'starts_at' => $interval[0],
                'ends_at' => $interval[1],
                'type' => $type,
                'reason' => $reason,
                'series_id' => $series,
                'created_by' => $userId,
            ]), $intervals);
        });
    }

    // ——————————————————————————— Utilitare ———————————————————————————

    public function findByManageToken(string $token): ?Booking
    {
        return Booking::query()->where('manage_token_hash', hash('sha256', $token))->first();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function occupiedWindow(Package $package, CarbonImmutable $startsAt, int $extraMinutes = 0): array
    {
        return [
            $startsAt->subMinutes((int) $package->buffer_before_minutes),
            $startsAt->addMinutes((int) $package->slot_minutes + $extraMinutes + (int) $package->buffer_after_minutes),
        ];
    }

    public function recordEvent(Booking $booking, string $type, ?string $from, ?string $to, string $actorType = 'system', ?int $actorId = null, array $data = []): void
    {
        BookingEvent::query()->create([
            'booking_id' => $booking->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'data' => $data ?: null,
        ]);
    }

    private function canBookInstantly(Package $package, BookingData $data): bool
    {
        if ($data->isStaff()) {
            return $package->hasScheduling();
        }

        return $this->settings->bool('booking.online_enabled') && $package->supportsInstantBooking();
    }

    private function initialStatus(BookingData $data, Quote $quote): array
    {
        if ($data->isStaff()) {
            return [$data->staffStatus === 'pending' ? 'pending' : 'confirmed', null];
        }

        $deposit = (int) ($quote->depositMinor ?? 0);
        if ($deposit > 0 && $data->paymentMethod === 'online') {
            return ['pending', CarbonImmutable::now()->addMinutes((int) $this->settings->int('booking.pending_minutes_online', 30))];
        }
        if ($deposit > 0 && $data->paymentMethod === 'bank_transfer') {
            return ['pending', CarbonImmutable::now()->addHours((int) $this->settings->int('booking.pending_hours_transfer', 48))];
        }
        if ($this->settings->bool('booking.auto_confirm')) {
            return ['confirmed', null];
        }
        $hours = (int) $this->settings->int('booking.pending_hours_unconfirmed', 0);

        return ['pending', $hours > 0 ? CarbonImmutable::now()->addHours($hours) : null];
    }

    private function assertBookablePackage(Package $package, int $participants): void
    {
        if (! $package->is_active || ! $package->supportsInstantBooking()) {
            throw new BookingException('request_only', 'Pentru acest pachet trimitem o cerere de disponibilitate, iar echipa revine cu confirmarea.');
        }
        $this->assertParticipants($package, $participants);
    }

    private function assertParticipants(Package $package, int $participants): void
    {
        if ($participants < 1) {
            throw new BookingException('participants', 'Numărul de participanți trebuie să fie cel puțin 1.');
        }
        if ($package->min_participants && $participants < $package->min_participants) {
            throw new BookingException('participants', "Pachetul este pentru minimum {$package->min_participants} participanți.");
        }
        if ($package->max_participants && $participants > $package->max_participants) {
            throw new BookingException('participants', "Pachetul este pentru maximum {$package->max_participants} participanți.");
        }
    }

    /** Documentele care trebuie acceptate explicit: termenii și politica de rezervare (+ regulile pentru minori, dacă există minori). */
    public function requiredPolicyTypes(array $ageGroups): array
    {
        $types = ['terms', 'booking_policy'];
        $minors = array_sum(array_map('intval', array_intersect_key($ageGroups, array_flip(['under_12', '12_17']))));
        if ($minors > 0) {
            $types[] = 'minors';
        }

        return array_values(array_filter($types, fn ($type) => LegalDocument::current($type) !== null));
    }

    private function assertRequiredPolicies(BookingData $data): void
    {
        $missing = array_diff($this->requiredPolicyTypes($data->ageGroups), $data->acceptedPolicyTypes);
        if ($missing) {
            throw new BookingException('policies', 'Pentru a trimite rezervarea, confirmă că ai citit și accepți condițiile marcate.');
        }
    }

    private function policyRecords(BookingData $data): array
    {
        $records = [];
        foreach ($data->acceptedPolicyTypes as $type) {
            $document = LegalDocument::current($type);
            if ($document) {
                $records[] = $document->acceptanceRecord();
            }
        }

        return $records;
    }

    private function generateCode(): string
    {
        $code = '';
        for ($i = 0; $i < 7; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return 'AW'.$code;
    }
}
