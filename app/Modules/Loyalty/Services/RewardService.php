<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Crm\Models\Customer;
use App\Modules\Loyalty\Models\Reward;
use App\Modules\Loyalty\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Schimbarea punctelor pe o recompensă: punctele se scad și voucherul se emite în aceeași tranzacție,
 * deci nu poate exista voucher fără puncte scăzute (sau invers).
 */
class RewardService
{
    public function __construct(private readonly LoyaltyService $loyalty, private readonly VoucherService $vouchers) {}

    public function redeem(Customer $customer, Reward $reward, string $idempotencyKey, ?int $userId = null): Voucher
    {
        if (! $reward->is_active) {
            throw new LoyaltyException('inactive', 'Recompensa nu mai este disponibilă.');
        }

        return DB::transaction(function () use ($customer, $reward, $idempotencyKey, $userId) {
            $entry = $this->loyalty->redeemPoints($customer, (int) $reward->points_cost, 'reward:'.$idempotencyKey, $reward, $userId, 'Recompensă: '.$reward->getTranslation('name', 'ro'));
            $existing = Voucher::query()->where('origin', 'reward')->whereJsonContains('conditions->ledger_entry_id', $entry->id)->first();
            if ($existing) {
                return $existing;
            }

            return $this->vouchers->issue([
                'type' => $reward->reward_type === 'voucher_package' ? 'package' : 'value',
                'initial_value_minor' => $reward->value_minor,
                'package_id' => $reward->package_id,
                'valid_until' => now()->addDays((int) $reward->voucher_valid_days)->toDateString(),
                'origin' => 'reward',
                'buyer_customer_id' => $customer->id,
                'allows_partial_use' => false,
                'conditions' => ['ledger_entry_id' => $entry->id, 'reward_id' => $reward->id],
            ], $userId);
        });
    }
}
