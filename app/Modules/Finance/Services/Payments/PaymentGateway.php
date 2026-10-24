<?php

namespace App\Modules\Finance\Services\Payments;

use App\Modules\Finance\Models\Payment;
use Illuminate\Http\Request;

/**
 * Adaptor pentru un procesator de plăți. Datele cardului sunt introduse exclusiv pe pagina procesatorului;
 * aplicația primește doar identificatori și statusuri.
 */
interface PaymentGateway
{
    public function name(): string;

    /** @return array{url: string, reference: string, expires_at: ?\DateTimeInterface} */
    public function createCheckout(Payment $payment, string $description, string $successUrl, string $cancelUrl): array;

    public function verifyWebhook(Request $request): bool;

    /** @return array<int, array{event_id: string, type: string, reference: ?string, payment_uuid: ?string, status: string, amount_minor: ?int, data: array}> status: succeeded | failed | expired | refunded | ignored */
    public function parseWebhook(Request $request): array;

    /** @return array{ok: bool, reference: ?string, error: ?string} */
    public function refund(Payment $payment, int $amountMinor, string $idempotencyKey): array;
}
