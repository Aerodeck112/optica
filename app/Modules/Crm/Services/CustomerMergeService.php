<?php

namespace App\Modules\Crm\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Crm\Models\Consent;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\CustomerNote;
use App\Modules\Loyalty\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Unește un client duplicat în clientul păstrat. Istoricul (rezervări, facturi, mesaje, consimțăminte)
 * trece pe clientul păstrat; soldul de puncte se transferă prin registru; duplicatul rămâne ca referință
 * („unit în #id”), fără date de contact, ca să nu mai fie găsit la deduplicare.
 */
class CustomerMergeService
{
    /** Tabele cu customer_id mutate integral pe clientul păstrat. */
    private const MOVE = ['bookings', 'leads', 'customer_notes', 'consent_events', 'message_logs', 'invoices', 'payments', 'data_requests'];

    public function __construct(private readonly LoyaltyService $loyalty, private readonly AuditLogger $audit) {}

    public function merge(Customer $keep, Customer $duplicate, ?int $userId): Customer
    {
        if ($keep->is($duplicate)) {
            throw new InvalidArgumentException('Nu poți uni un client cu el însuși.');
        }
        if ($keep->merged_into_id || $duplicate->merged_into_id || $keep->anonymized_at || $duplicate->anonymized_at) {
            throw new InvalidArgumentException('Clienții deja uniți sau anonimizați nu mai pot fi uniți.');
        }

        return DB::transaction(function () use ($keep, $duplicate, $userId) {
            // ordinea fixă a blocărilor evită blocajele reciproce
            Customer::query()->whereIn('id', [$keep->id, $duplicate->id])->orderBy('id')->lockForUpdate()->get();

            $this->loyalty->transferBalance($duplicate, $keep, $userId);

            foreach (self::MOVE as $table) {
                DB::table($table)->where('customer_id', $duplicate->id)->update(['customer_id' => $keep->id]);
            }
            DB::table('loyalty_ledger')->where('customer_id', $duplicate->id)->update(['customer_id' => $keep->id]);
            DB::table('vouchers')->where('buyer_customer_id', $duplicate->id)->update(['buyer_customer_id' => $keep->id]);
            DB::table('referrals')->where('referrer_id', $duplicate->id)->update(['referrer_id' => $keep->id]);
            DB::table('campaign_recipients')->where('customer_id', $duplicate->id)
                ->whereNotIn('campaign_id', DB::table('campaign_recipients')->where('customer_id', $keep->id)->select('campaign_id'))
                ->update(['customer_id' => $keep->id]);
            DB::table('customer_login_tokens')->where('customer_id', $duplicate->id)->delete();

            $this->mergeConsents($keep, $duplicate);
            $this->fillMissingFields($keep, $duplicate);

            $keep->forceFill([
                'bookings_count' => DB::table('bookings')->where('customer_id', $keep->id)->count(),
                'visits_count' => DB::table('bookings')->where('customer_id', $keep->id)->whereIn('status', ['checked_in', 'completed'])->count(),
                'paid_total_minor' => (int) DB::table('bookings')->where('customer_id', $keep->id)->sum('paid_minor'),
                'first_booking_at' => DB::table('bookings')->where('customer_id', $keep->id)->min('created_at'),
                'last_booking_at' => DB::table('bookings')->where('customer_id', $keep->id)->max('created_at'),
            ])->save();

            $duplicate->forceFill([
                'merged_into_id' => $keep->id,
                'email' => null, 'email_normalized' => null, 'phone' => null, 'phone_normalized' => null,
                'referral_code' => null,
            ])->save();

            CustomerNote::query()->create([
                'customer_id' => $keep->id,
                'user_id' => $userId,
                'body' => "Clientul #{$duplicate->id} a fost unit în această fișă.",
                'visibility' => 'staff',
            ]);
            $this->audit->log('customer.merged', $keep, ['duplicate_id' => $duplicate->id]);

            return $keep->refresh();
        });
    }

    /**
     * Pe fiecare canal rămâne starea cea mai recentă: o retragere mai nouă câștigă în fața unei acordări mai vechi.
     */
    private function mergeConsents(Customer $keep, Customer $duplicate): void
    {
        foreach (Consent::query()->where('customer_id', $duplicate->id)->get() as $consent) {
            $existing = Consent::query()->where('customer_id', $keep->id)->where('channel', $consent->channel)->where('purpose', $consent->purpose)->first();
            $at = fn (Consent $c) => max($c->granted_at?->getTimestamp() ?? 0, $c->withdrawn_at?->getTimestamp() ?? 0, $c->updated_at?->getTimestamp() ?? 0);
            if (! $existing) {
                $consent->forceFill(['customer_id' => $keep->id])->save();
            } elseif ($at($consent) > $at($existing)) {
                $existing->forceFill($consent->only(['status', 'source', 'proof', 'granted_at', 'withdrawn_at']))->save();
                $consent->delete();
            } else {
                $consent->delete();
            }
        }
    }

    private function fillMissingFields(Customer $keep, Customer $duplicate): void
    {
        $fields = ['first_name', 'last_name', 'email', 'email_normalized', 'phone', 'phone_normalized', 'company_name', 'company_tax_id', 'company_reg_no', 'billing_address', 'billing_city', 'billing_county'];
        $updates = [];
        foreach ($fields as $field) {
            if (blank($keep->{$field}) && filled($duplicate->{$field})) {
                $updates[$field] = $duplicate->{$field};
            }
        }
        if ($updates) {
            // golim întâi câmpurile unice pe duplicat, ca să nu existe două înregistrări cu același contact
            $duplicate->forceFill(array_fill_keys(array_keys($updates), null))->save();
            $keep->forceFill($updates)->save();
        }
    }
}
