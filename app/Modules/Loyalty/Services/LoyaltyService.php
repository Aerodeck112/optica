<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Models\LoyaltyTier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Registru de puncte append-only. Fiecare mișcare blochează rândul clientului (SELECT … FOR UPDATE),
 * are cheie de idempotență unică și păstrează soldul după operație. Nu există sold editabil direct.
 */
class LoyaltyService
{
    public function __construct(private readonly SettingsRepository $settings, private readonly AuditLogger $audit) {}

    public function enabled(): bool
    {
        return $this->settings->bool('loyalty.enabled');
    }

    public function balance(Customer $customer): int
    {
        return (int) (LoyaltyLedgerEntry::query()->where('customer_id', $customer->id)->latest('id')->value('balance_after') ?? 0);
    }

    public function lifetimeEarned(Customer $customer): int
    {
        return (int) LoyaltyLedgerEntry::query()->where('customer_id', $customer->id)->whereIn('type', ['earn', 'referral', 'adjust'])->where('delta', '>', 0)->sum('delta');
    }

    /**
     * Mișcare generică. Întoarce intrarea existentă dacă cheia de idempotență a mai fost folosită.
     * Debitele care ar duce soldul sub zero sunt refuzate (cu excepția corecțiilor explicite permise).
     */
    public function post(Customer $customer, int $delta, string $type, string $idempotencyKey, ?Model $reference = null, ?string $note = null, ?int $userId = null, ?CarbonImmutable $expiresAt = null, bool $allowNegative = false): LoyaltyLedgerEntry
    {
        if ($delta === 0) {
            throw new LoyaltyException('zero', 'Numărul de puncte trebuie să fie diferit de zero.');
        }

        return DB::transaction(function () use ($customer, $delta, $type, $idempotencyKey, $reference, $note, $userId, $expiresAt, $allowNegative) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $existing = LoyaltyLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $balance = $this->balance($customer);
            if ($delta < 0 && $balance + $delta < 0 && ! $allowNegative) {
                throw new LoyaltyException('insufficient', "Sold insuficient: clientul are {$balance} puncte.");
            }

            $entry = LoyaltyLedgerEntry::query()->create([
                'customer_id' => $customer->id,
                'delta' => $delta,
                'balance_after' => $balance + $delta,
                'type' => $type,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'idempotency_key' => $idempotencyKey,
                'note' => $note ? mb_substr($note, 0, 255) : null,
                'created_by' => $userId,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);

            if ($delta > 0) {
                $this->refreshTier($customer);
            }

            return $entry;
        }, 5);
    }

    /** Puncte pentru o rezervare finalizată și achitată (o singură dată per rezervare). */
    public function awardForBooking(Booking $booking): ?LoyaltyLedgerEntry
    {
        if (! $this->enabled() || ! $booking->customer || $booking->status !== 'completed' || $booking->payment_status !== 'paid') {
            return null;
        }
        $paidRon = intdiv(max(0, (int) $booking->paid_minor - (int) $booking->refunded_minor), 100);
        $multiplier = (float) ($booking->customer->tier?->earn_multiplier ?? 1);
        $points = (int) floor($paidRon * max(0, $this->settings->int('loyalty.points_per_ron', 1)) * $multiplier);
        if ($points <= 0) {
            return null;
        }
        $months = (int) $this->settings->int('loyalty.points_expiry_months', 12);

        return $this->post($booking->customer, $points, 'earn', "booking:{$booking->id}:earn", $booking, "Rezervarea {$booking->code}", null, $months > 0 ? CarbonImmutable::now()->addMonths($months) : null);
    }

    /** Anulează punctele câștigate pentru o rezervare rambursată (cât permite soldul; restul rămâne consemnat în notă). */
    public function reverseForBooking(Booking $booking, ?int $userId = null): ?LoyaltyLedgerEntry
    {
        $earned = LoyaltyLedgerEntry::query()->where('idempotency_key', "booking:{$booking->id}:earn")->first();
        if (! $earned || ! $booking->customer) {
            return null;
        }

        return DB::transaction(function () use ($booking, $earned, $userId) {
            Customer::query()->whereKey($booking->customer_id)->lockForUpdate()->first();
            $amount = min($earned->delta, $this->balance($booking->customer));
            if ($amount <= 0) {
                return null;
            }
            $note = $amount < $earned->delta ? "Anulare parțială ({$amount} din {$earned->delta}); restul punctelor fuseseră deja folosite" : "Anulare puncte rezervarea {$booking->code}";

            return $this->post($booking->customer, -$amount, 'reversal', "booking:{$booking->id}:reversal", $booking, $note, $userId);
        });
    }

    public function redeemPoints(Customer $customer, int $points, string $idempotencyKey, ?Model $reference = null, ?int $userId = null, ?string $note = null): LoyaltyLedgerEntry
    {
        if ($points <= 0) {
            throw new LoyaltyException('invalid', 'Numărul de puncte trebuie să fie pozitiv.');
        }

        return $this->post($customer, -$points, 'redeem', $idempotencyKey, $reference, $note ?? 'Utilizare puncte', $userId);
    }

    public function adjust(Customer $customer, int $delta, string $reason, int $userId): LoyaltyLedgerEntry
    {
        $entry = $this->post($customer, $delta, 'adjust', 'adjust:'.$customer->id.':'.now()->format('YmdHisv').':'.$userId, null, $reason, $userId);
        $this->audit->log('loyalty.adjusted', $customer, ['delta' => $delta, 'reason' => $reason]);

        return $entry;
    }

    /**
     * Expirare FIFO: punctele cheltuite consumă întâi cele mai vechi puncte. Suma de expirat =
     * câștigurile expirate − (consumuri + expirări anterioare), limitată la soldul curent.
     */
    public function expireFor(Customer $customer, ?CarbonImmutable $now = null): ?LoyaltyLedgerEntry
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($customer, $now) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            $entries = LoyaltyLedgerEntry::query()->where('customer_id', $customer->id);
            $expiredEarned = (int) (clone $entries)->where('delta', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', $now)->sum('delta');
            if ($expiredEarned === 0) {
                return null;
            }
            $consumed = (int) abs((clone $entries)->where('delta', '<', 0)->sum('delta'));
            $toExpire = min($this->balance($customer), max(0, $expiredEarned - $consumed));
            if ($toExpire <= 0) {
                return null;
            }

            return $this->post($customer, -$toExpire, 'expire', 'expire:'.$customer->id.':'.$now->format('YmdHis'), null, 'Puncte expirate');
        });
    }

    public function expireAll(): int
    {
        $count = 0;
        $ids = LoyaltyLedgerEntry::query()->where('delta', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())->distinct()->pluck('customer_id');
        foreach (Customer::query()->whereIn('id', $ids)->cursor() as $customer) {
            if ($this->expireFor($customer)) {
                $count++;
            }
        }

        return $count;
    }

    /** La unificarea clienților: soldul trece pe clientul păstrat, prin două mișcări consemnate. */
    public function transferBalance(Customer $from, Customer $to, ?int $userId): void
    {
        $balance = $this->balance($from);
        if ($balance <= 0) {
            return;
        }
        $key = "merge:{$from->id}:{$to->id}";
        $this->post($from, -$balance, 'adjust', $key.':out', $to, "Transfer la clientul #{$to->id} (unificare)", $userId);
        $this->post($to, $balance, 'adjust', $key.':in', $from, "Transfer de la clientul #{$from->id} (unificare)", $userId);
    }

    public function refreshTier(Customer $customer): void
    {
        $lifetime = $this->lifetimeEarned($customer);
        $tier = LoyaltyTier::query()->where('min_lifetime_points', '<=', $lifetime)->orderByDesc('min_lifetime_points')->first();
        if ($customer->loyalty_tier_id !== $tier?->id) {
            Customer::query()->whereKey($customer->id)->update(['loyalty_tier_id' => $tier?->id]);
            $customer->loyalty_tier_id = $tier?->id;
        }
    }
}
