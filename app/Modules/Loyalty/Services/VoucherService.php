<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Loyalty\Models\Voucher;
use App\Modules\Loyalty\Models\VoucherTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Vouchere valorice sau pentru un pachet. Utilizarea blochează rândul voucherului, deci două folosiri simultane
 * nu pot consuma mai mult decât soldul. Fiecare mișcare are cheie de idempotență.
 */
class VoucherService
{
    private const ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    public function __construct(private readonly AuditLogger $audit) {}

    public function issue(array $data, ?int $userId = null): Voucher
    {
        return DB::transaction(function () use ($data, $userId) {
            $value = $data['type'] === 'value' ? (int) $data['initial_value_minor'] : null;
            if ($data['type'] === 'value' && $value <= 0) {
                throw new LoyaltyException('invalid', 'Valoarea voucherului trebuie să fie mai mare decât 0.');
            }
            $voucher = null;
            for ($i = 0; $i < 5 && ! $voucher; $i++) {
                try {
                    $voucher = Voucher::query()->create([
                        'code' => $data['code'] ?? $this->generateCode(),
                        'type' => $data['type'],
                        'package_id' => $data['package_id'] ?? null,
                        'package_participants' => $data['package_participants'] ?? null,
                        'initial_value_minor' => $value,
                        'balance_minor' => $value ?? 0,
                        'allows_partial_use' => (bool) ($data['allows_partial_use'] ?? true),
                        'valid_from' => $data['valid_from'] ?? now()->toDateString(),
                        'valid_until' => $data['valid_until'] ?? null,
                        'status' => $data['status'] ?? 'active',
                        'conditions' => $data['conditions'] ?? null,
                        'origin' => $data['origin'] ?? 'sale',
                        'buyer_customer_id' => $data['buyer_customer_id'] ?? null,
                        'recipient_name' => $data['recipient_name'] ?? null,
                        'message' => $data['message'] ?? null,
                        'legal_document_version_id' => $data['legal_document_version_id'] ?? null,
                        'created_by' => $userId,
                    ]);
                } catch (QueryException $e) {
                    if (isset($data['code']) || ! str_contains($e->getMessage(), 'vouchers_code_unique')) {
                        throw $e;
                    }
                }
            }
            VoucherTransaction::query()->create([
                'voucher_id' => $voucher->id,
                'amount_minor' => $value ?? 0,
                'balance_after_minor' => $value ?? 0,
                'type' => 'issue',
                'idempotency_key' => "voucher:{$voucher->id}:issue",
                'created_by' => $userId,
                'created_at' => now(),
            ]);
            $this->audit->log('voucher.issued', $voucher, ['type' => $voucher->type, 'value' => $value]);

            return $voucher;
        });
    }

    /**
     * Folosește un voucher. Pentru voucherele valorice, $amountMinor este suma de folosit;
     * pentru cele de tip pachet, voucherul se consumă integral (o singură utilizare).
     */
    public function redeem(string $code, int $amountMinor, string $idempotencyKey, ?Booking $booking, ?int $userId = null, ?CarbonImmutable $on = null): VoucherTransaction
    {
        $code = Voucher::normalizeCode($code);

        return DB::transaction(function () use ($code, $amountMinor, $idempotencyKey, $booking, $userId, $on) {
            $existing = VoucherTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            /** @var Voucher|null $voucher */
            $voucher = Voucher::query()->where('code', $code)->lockForUpdate()->first();
            if (! $voucher) {
                throw new LoyaltyException('not_found', 'Codul voucherului nu există.');
            }
            if (! $voucher->isUsableOn($on ?? now())) {
                throw new LoyaltyException('not_usable', match ($voucher->status) {
                    'redeemed' => 'Voucherul a fost deja folosit integral.',
                    'expired' => 'Voucherul a expirat.',
                    'cancelled' => 'Voucherul a fost anulat.',
                    default => 'Voucherul nu este valabil la această dată.',
                });
            }
            if ($voucher->type === 'package') {
                $amount = 0;
                $balanceAfter = 0;
            } else {
                if ($amountMinor <= 0) {
                    throw new LoyaltyException('invalid', 'Suma trebuie să fie pozitivă.');
                }
                if ($amountMinor > $voucher->balance_minor) {
                    throw new LoyaltyException('insufficient', 'Soldul voucherului este insuficient.');
                }
                if (! $voucher->allows_partial_use && $amountMinor !== (int) $voucher->balance_minor) {
                    throw new LoyaltyException('full_use_only', 'Acest voucher se folosește integral, într-o singură rezervare.');
                }
                $amount = $amountMinor;
                $balanceAfter = (int) $voucher->balance_minor - $amountMinor;
            }

            $tx = VoucherTransaction::query()->create([
                'voucher_id' => $voucher->id,
                'amount_minor' => -$amount,
                'balance_after_minor' => $balanceAfter,
                'type' => 'redeem',
                'booking_id' => $booking?->id,
                'idempotency_key' => $idempotencyKey,
                'note' => $booking ? "Rezervarea {$booking->code}" : null,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
            $voucher->forceFill([
                'balance_minor' => $balanceAfter,
                'status' => $voucher->type === 'package' || $balanceAfter === 0 ? 'redeemed' : 'active',
            ])->save();

            return $tx;
        }, 5);
    }

    /** Restituie o utilizare (ex. rezervare anulată înainte de vizită). */
    public function reverse(VoucherTransaction $redemption, ?int $userId, string $reason): VoucherTransaction
    {
        return DB::transaction(function () use ($redemption, $userId, $reason) {
            $voucher = Voucher::query()->whereKey($redemption->voucher_id)->lockForUpdate()->firstOrFail();
            $key = "voucher-tx:{$redemption->id}:reverse";
            if ($existing = VoucherTransaction::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }
            $amount = abs((int) $redemption->amount_minor);
            $balance = (int) $voucher->balance_minor + $amount;
            $tx = VoucherTransaction::query()->create([
                'voucher_id' => $voucher->id, 'amount_minor' => $amount, 'balance_after_minor' => $balance, 'type' => 'refund',
                'booking_id' => $redemption->booking_id, 'idempotency_key' => $key, 'note' => mb_substr($reason, 0, 255), 'created_by' => $userId, 'created_at' => now(),
            ]);
            $stillValid = $voucher->valid_until === null || $voucher->valid_until->isFuture() || $voucher->valid_until->isToday();
            $voucher->forceFill(['balance_minor' => $balance, 'status' => $stillValid ? 'active' : 'expired'])->save();
            $this->audit->log('voucher.redemption_reversed', $voucher, ['transaction_id' => $redemption->id, 'reason' => $reason]);

            return $tx;
        });
    }

    public function cancel(Voucher $voucher, int $userId, string $reason): void
    {
        DB::transaction(function () use ($voucher, $userId, $reason) {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            VoucherTransaction::query()->create([
                'voucher_id' => $voucher->id, 'amount_minor' => -(int) $voucher->balance_minor, 'balance_after_minor' => 0, 'type' => 'cancel',
                'idempotency_key' => "voucher:{$voucher->id}:cancel", 'note' => mb_substr($reason, 0, 255), 'created_by' => $userId, 'created_at' => now(),
            ]);
            $voucher->forceFill(['status' => 'cancelled', 'balance_minor' => 0])->save();
            $this->audit->log('voucher.cancelled', $voucher, ['reason' => $reason]);
        });
    }

    /** Marchează expirate voucherele cu data depășită (rulat zilnic). */
    public function expireDue(): int
    {
        $count = 0;
        Voucher::query()->where('status', 'active')->whereNotNull('valid_until')->where('valid_until', '<', today())->each(function (Voucher $voucher) use (&$count) {
            DB::transaction(function () use ($voucher) {
                $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->first();
                if ($voucher->status !== 'active') {
                    return;
                }
                VoucherTransaction::query()->create([
                    'voucher_id' => $voucher->id, 'amount_minor' => -(int) $voucher->balance_minor, 'balance_after_minor' => 0, 'type' => 'expire',
                    'idempotency_key' => "voucher:{$voucher->id}:expire", 'note' => 'Expirat', 'created_at' => now(),
                ]);
                $voucher->forceFill(['status' => 'expired', 'balance_minor' => 0])->save();
            });
            $count++;
        });

        return $count;
    }

    private function generateCode(): string
    {
        $code = 'AWV';
        for ($i = 0; $i < 9; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
