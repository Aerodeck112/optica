<?php

namespace App\Modules\Crm\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Core\Models\DataRequest;
use App\Modules\Crm\Models\Customer;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Drepturile persoanei vizate (GDPR): export al datelor (art. 15/20) și ștergere prin anonimizare (art. 17).
 * Documentele fiscale emise se păstrează pe durata obligației legale (Legea contabilității nr. 82/1991)
 * și nu se modifică; restul datelor de contact sunt eliminate.
 */
class CustomerDataService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly LoyaltyService $loyalty,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> toate datele personale ale clientului, în format structurat */
    public function collect(Customer $customer): array
    {
        $customer->loadMissing(['bookings.package', 'consents', 'consentEvents', 'leads', 'vouchersBought']);

        return [
            'generated_at' => now()->toIso8601String(),
            'controller' => setting('company.legal_name'),
            'customer' => $customer->only(['id', 'type', 'first_name', 'last_name', 'email', 'phone', 'company_name', 'company_tax_id', 'billing_address', 'billing_city', 'billing_county', 'billing_country', 'locale', 'source', 'created_at']),
            'bookings' => $customer->bookings->map(fn (Booking $b) => [
                'code' => $b->code,
                'package' => $b->package?->getTranslation('name', 'ro'),
                'starts_at' => LocalTime::format($b->starts_at),
                'participants' => $b->participants,
                'age_groups' => $b->age_groups,
                'status' => $b->statusLabel(),
                'payment_status' => $b->paymentStatusLabel(),
                'total' => $b->total_minor,
                'contact' => ['name' => $b->contact_name, 'email' => $b->contact_email, 'phone' => $b->contact_phone],
                'message' => $b->customer_notes,
                'accepted_policies' => $b->accepted_policies,
                'created_at' => $b->created_at?->toIso8601String(),
            ])->all(),
            'event_requests' => $customer->leads->map(fn ($l) => $l->only(['reference', 'type', 'stage', 'preferred_date', 'participants', 'message', 'created_at']))->all(),
            'marketing_consents' => $customer->consents->map(fn ($c) => $c->only(['channel', 'purpose', 'status', 'source', 'granted_at', 'withdrawn_at']))->all(),
            'consent_history' => $customer->consentEvents->map(fn ($e) => $e->only(['channel', 'purpose', 'action', 'source', 'created_at']))->all(),
            'messages_sent' => DB::table('message_logs')->where('customer_id', $customer->id)->get(['channel', 'category', 'template_key', 'subject', 'status', 'created_at'])->all(),
            'invoices' => DB::table('invoices')->where('customer_id', $customer->id)->whereNotNull('full_number')->get(['full_number', 'issue_date', 'gross_total_minor', 'currency'])->all(),
            'loyalty' => [
                'balance' => $this->loyalty->balance($customer),
                'movements' => DB::table('loyalty_ledger')->where('customer_id', $customer->id)->get(['delta', 'type', 'note', 'created_at'])->all(),
            ],
            'vouchers_bought' => $customer->vouchersBought->map(fn ($v) => $v->only(['code', 'type', 'status', 'valid_until']))->all(),
        ];
    }

    public function export(Customer $customer, ?int $userId, string $channel = 'admin'): DataRequest
    {
        $path = 'exports/client-'.$customer->id.'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($this->collect($customer), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $request = DataRequest::query()->create([
            'customer_id' => $customer->id,
            'type' => 'export',
            'status' => 'completed',
            'channel' => $channel,
            'export_path' => $path,
            'due_on' => now()->addMonth()->toDateString(),
            'handled_by' => $userId,
            'completed_at' => now(),
        ]);
        $this->audit->log('customer.data_exported', $customer, ['data_request_id' => $request->id]);

        return $request;
    }

    /** Motive pentru care anonimizarea nu se poate face acum (ex. rezervări viitoare active). */
    public function blockers(Customer $customer): array
    {
        $blockers = [];
        $upcoming = Booking::query()->where('customer_id', $customer->id)->whereIn('status', ['request', 'pending', 'confirmed', 'checked_in'])->count();
        if ($upcoming > 0) {
            $blockers[] = "{$upcoming} rezervări active — anulează-le sau finalizează-le înainte.";
        }
        $unpaid = Booking::query()->where('customer_id', $customer->id)->where('status', 'completed')->whereIn('payment_status', ['unpaid', 'deposit_paid', 'partially_paid'])->count();
        if ($unpaid > 0) {
            $blockers[] = "{$unpaid} rezervări finalizate neachitate integral.";
        }

        return $blockers;
    }

    public function anonymize(Customer $customer, ?int $userId, string $reason): DataRequest
    {
        if ($customer->isAnonymized()) {
            throw new RuntimeException('Clientul este deja anonimizat.');
        }
        if ($blockers = $this->blockers($customer)) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        return DB::transaction(function () use ($customer, $userId, $reason) {
            // excluderea pe bază de hash previne recontactarea fără a păstra adresa în clar
            $this->consents->suppress('email', $customer->email, 'erasure', 'all', 'Cerere de ștergere');
            $this->consents->suppress('sms', $customer->phone, 'erasure', 'all', 'Cerere de ștergere');

            DB::table('consents')->where('customer_id', $customer->id)->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'proof' => null]);
            DB::table('customer_notes')->where('customer_id', $customer->id)->delete();
            DB::table('customer_login_tokens')->where('customer_id', $customer->id)->delete();
            DB::table('bookings')->where('customer_id', $customer->id)->update([
                'contact_name' => 'Client anonimizat', 'contact_email' => null, 'contact_phone' => null,
                'customer_notes' => null, 'internal_notes' => null, 'analytics_client_ref' => null,
                'company_name' => $customer->type === 'company' ? DB::raw('company_name') : null,
            ]);
            DB::table('leads')->where('customer_id', $customer->id)->update([
                'contact_name' => 'Client anonimizat', 'contact_email' => null, 'contact_phone' => null, 'message' => null,
            ]);
            DB::table('vouchers')->where('buyer_customer_id', $customer->id)->update(['recipient_name' => null, 'message' => null]);

            $customer->forceFill([
                'first_name' => 'Client', 'last_name' => 'anonimizat',
                'email' => null, 'email_normalized' => null, 'phone' => null, 'phone_normalized' => null,
                'billing_address' => null, 'billing_city' => null, 'billing_county' => null,
                'company_name' => $customer->type === 'company' ? $customer->company_name : null,
                'tags' => null, 'interests' => null, 'referral_code' => null,
                'anonymized_at' => now(),
            ])->save();

            $request = DataRequest::query()->create([
                'customer_id' => $customer->id,
                'type' => 'erasure',
                'status' => 'completed',
                'channel' => 'admin',
                'details' => mb_substr($reason, 0, 1000),
                'outcome' => 'Date de contact și note eliminate. Facturile emise se păstrează pe durata obligației legale de arhivare.',
                'due_on' => now()->addMonth()->toDateString(),
                'handled_by' => $userId,
                'completed_at' => now(),
            ]);
            $this->audit->log('customer.anonymized', $customer, ['data_request_id' => $request->id]);

            return $request;
        });
    }
}
