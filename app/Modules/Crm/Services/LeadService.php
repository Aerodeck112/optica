<?php

namespace App\Modules\Crm\Services;

use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Package;
use App\Modules\Crm\Events\LeadCreated;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pipeline pentru aniversări, evenimente private și team building.
 * Cerere → Contactat → Ofertă trimisă → În negociere → Confirmat → Finalizat / Pierdut.
 */
class LeadService
{
    public function __construct(
        private readonly CustomerResolver $customers,
        private readonly ConsentService $consents,
    ) {}

    public function create(array $data, string $source = 'web', array $marketing = [], array $consentProof = []): Lead
    {
        $lead = DB::transaction(function () use ($data, $source, $marketing, $consentProof) {
            $customer = $this->customers->resolve([
                'name' => $data['contact_name'],
                'email' => $data['contact_email'] ?? null,
                'phone' => $data['contact_phone'] ?? null,
                'company_name' => $data['company_name'] ?? null,
                'locale' => $data['locale'] ?? 'ro',
            ], $source);

            $lead = Lead::query()->create([
                'reference' => $this->reference(),
                'customer_id' => $customer->id,
                'type' => $data['type'],
                'stage' => 'new',
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'company_name' => $data['company_name'] ?? null,
                'preferred_date' => $data['preferred_date'] ?? null,
                'preferred_time' => $data['preferred_time'] ?? null,
                'participants' => $data['participants'] ?? null,
                'age_groups' => $data['age_groups'] ?? null,
                'package_id' => $data['package_id'] ?? null,
                'message' => $data['message'] ?? null,
                'source' => $source,
                'stage_changed_at' => now(),
            ]);

            foreach (['email', 'sms'] as $channel) {
                if (! empty($marketing[$channel])) {
                    $this->consents->grant($customer, $channel, 'lead_form', $consentProof + ['lead' => $lead->reference]);
                }
            }

            return $lead;
        });

        LeadCreated::dispatch($lead);

        return $lead;
    }

    public function moveTo(Lead $lead, string $stage, ?int $userId = null, ?string $note = null, ?string $lostReason = null): Lead
    {
        if (! array_key_exists($stage, Lead::STAGES)) {
            throw new \InvalidArgumentException('Etapă necunoscută.');
        }
        $from = $lead->stage;
        DB::transaction(function () use ($lead, $stage, $userId, $note, $lostReason, $from) {
            $lead->forceFill(['stage' => $stage, 'stage_changed_at' => now(), 'lost_reason' => $stage === 'lost' ? $lostReason : $lead->lost_reason])->save();
            LeadActivity::query()->create([
                'lead_id' => $lead->id,
                'user_id' => $userId,
                'type' => 'stage_change',
                'body' => $note,
                'data' => ['from' => $from, 'to' => $stage],
            ]);
        });

        return $lead;
    }

    /**
     * Transformă o oportunitate confirmată în rezervare folosind același motor (verifică disponibilitatea).
     * Datele de contact nu se dublează: rezervarea se leagă de clientul și de cererea existente.
     */
    public function convertToBooking(Lead $lead, Package $package, CarbonImmutable $startsAt, int $participants, ?int $userId, ?int $resourceId = null): \App\Modules\Booking\Models\Booking
    {
        if ($lead->booking_id) {
            throw new \LogicException('Cererea are deja o rezervare asociată.');
        }

        $result = app(BookingService::class)->create(new BookingData(
            package: $package,
            startsAt: $startsAt,
            participants: $participants,
            contactName: $lead->contact_name,
            contactEmail: $lead->contact_email,
            contactPhone: $lead->contact_phone,
            source: 'lead',
            ageGroups: (array) $lead->age_groups,
            companyName: $lead->company_name,
            customerNotes: $lead->message,
            createdBy: $userId,
            resourceId: $resourceId,
            leadId: $lead->id,
            staffStatus: 'confirmed',
        ));

        $lead->forceFill(['booking_id' => $result['booking']->id])->save();
        if (! in_array($lead->stage, ['confirmed', 'completed'], true)) {
            $this->moveTo($lead, 'confirmed', $userId, 'Rezervare creată: '.$result['booking']->code);
        }

        return $result['booking'];
    }

    private function reference(): string
    {
        do {
            $reference = 'CR-'.strtoupper(Str::random(6));
        } while (Lead::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
