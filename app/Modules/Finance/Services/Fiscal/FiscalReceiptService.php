<?php

namespace App\Modules\Finance\Services\Fiscal;

use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\FiscalDevice;
use App\Modules\Finance\Models\FiscalReceipt;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\VatCalculator;
use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bonuri fiscale. Aplicația nu vorbește direct cu aparatul: pune o comandă în coadă, iar agentul local o preia,
 * o tipărește și raportează rezultatul. O comandă preluată fără răspuns devine „incertă” și NU se retrimite
 * automat (risc de bon dublu) — se reconciliază manual din jurnalul aparatului.
 *
 * Simulatorul produce înregistrări marcate „SIMULARE – NU ESTE BON FISCAL”, pentru testarea fluxului.
 */
class FiscalReceiptService
{
    public const SIMULATION_LABEL = 'SIMULARE – NU ESTE BON FISCAL';

    public const OPEN_STATUSES = ['queued', 'dispatched', 'printed', 'uncertain', 'reconciled_printed'];

    public function __construct(
        private readonly VatCalculator $vat,
        private readonly AuditLogger $audit,
    ) {}

    public function defaultDevice(): ?FiscalDevice
    {
        return FiscalDevice::query()->where('is_active', true)->orderByRaw("driver = 'simulator'")->orderBy('id')->first();
    }

    /** Bon pentru o încasare la recepție (numerar / card POS). Cel mult un bon valabil pe încasare. */
    public function forPayment(Payment $payment, ?FiscalDevice $device, int $userId): FiscalReceipt
    {
        $device ??= $this->defaultDevice() ?? throw new FiscalException('Nu există o casă de marcat activă. Configurați una în Financiar → Case de marcat.');
        if (! in_array($payment->method, ['cash', 'card_pos'], true) || $payment->status !== 'succeeded') {
            throw new FiscalException('Bonul fiscal se emite doar pentru încasări reușite în numerar sau cu cardul la POS.');
        }

        return DB::transaction(function () use ($payment, $device, $userId) {
            Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (FiscalReceipt::query()->where('payment_id', $payment->id)->whereIn('status', self::OPEN_STATUSES)->exists()) {
                throw new FiscalException('Pentru această încasare există deja un bon emis sau în curs.');
            }

            return $this->queue($device, $this->itemsFor($payment), [['method' => $payment->method, 'amount_minor' => $payment->amount_minor]], $userId, $payment);
        });
    }

    /** Reemite după un eșec confirmat (bonul anterior NU a fost tipărit). */
    public function retry(FiscalReceipt $receipt, int $userId): FiscalReceipt
    {
        if (! in_array($receipt->status, ['failed', 'reconciled_not_printed'], true)) {
            throw new FiscalException('Se poate reemite doar un bon eșuat sau confirmat ca netipărit.');
        }

        return DB::transaction(function () use ($receipt, $userId) {
            $locked = FiscalReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (FiscalReceipt::query()->where('retry_of_id', $locked->id)->exists()) {
                throw new FiscalException('Bonul a fost deja reemis.');
            }
            if ($locked->payment_id && FiscalReceipt::query()->where('payment_id', $locked->payment_id)->whereIn('status', self::OPEN_STATUSES)->exists()) {
                throw new FiscalException('Pentru această încasare există deja un bon valabil.');
            }

            return $this->queue($locked->device, (array) $locked->items, (array) $locked->payments, $userId, $locked->payment, $locked);
        });
    }

    /** Reconciliere manuală a unui bon incert, pe baza jurnalului electronic / raportului Z. */
    public function reconcile(FiscalReceipt $receipt, bool $printed, ?string $fiscalNumber, string $note, int $userId): FiscalReceipt
    {
        $updated = FiscalReceipt::query()->whereKey($receipt->id)->whereIn('status', ['uncertain', 'dispatched'])->update([
            'status' => $printed ? 'reconciled_printed' : 'reconciled_not_printed',
            'fiscal_number' => $printed ? $fiscalNumber : null,
            'printed_at' => $printed ? now() : null,
            'reconciled_by' => $userId,
            'reconciliation_note' => mb_substr($note, 0, 1000),
            'updated_at' => now(),
        ]);
        if (! $updated) {
            throw new FiscalException('Bonul nu mai este în stare incertă.');
        }
        $this->audit->log('fiscal.reconciled', $receipt, ['printed' => $printed, 'fiscal_number' => $fiscalNumber]);

        return $receipt->fresh();
    }

    /** Agentul preia comenzile din coadă (claim atomic: o comandă este livrată o singură dată). */
    public function claimFor(FiscalDevice $device, int $limit = 5): array
    {
        $ids = FiscalReceipt::query()->where('fiscal_device_id', $device->id)->where('status', 'queued')->orderBy('id')->limit($limit)->pluck('id');
        $claimed = [];
        foreach ($ids as $id) {
            if (FiscalReceipt::query()->whereKey($id)->where('status', 'queued')->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()])) {
                $claimed[] = FiscalReceipt::query()->find($id);
            }
        }

        return array_map(fn (FiscalReceipt $receipt) => [
            'command_id' => $receipt->command_id,
            'type' => 'receipt',
            'items' => array_map(fn (array $item) => $item + ['vat_group' => $this->vatGroup($device, (string) $item['vat_rate'])], (array) $receipt->items),
            'payments' => array_map(fn (array $pay) => $pay + ['code' => $device->payment_mapping[$pay['method']] ?? $pay['method']], (array) $receipt->payments),
            'total_minor' => $receipt->total_minor,
        ], $claimed);
    }

    /** Rezultatul raportat de agent. Idempotent: un rezultat repetat pentru aceeași comandă nu schimbă nimic. */
    public function applyResult(FiscalDevice $device, string $commandId, array $result): ?FiscalReceipt
    {
        $receipt = FiscalReceipt::query()->where('command_id', $commandId)->where('fiscal_device_id', $device->id)->first();
        if (! $receipt) {
            return null;
        }
        $printed = ($result['status'] ?? '') === 'printed';
        // acceptăm rezultatul și pentru o comandă marcată între timp „incertă” (răspuns întârziat)
        FiscalReceipt::query()->whereKey($receipt->id)->whereIn('status', ['dispatched', 'uncertain'])->update([
            'status' => $printed ? 'printed' : 'failed',
            'fiscal_number' => $printed ? mb_substr((string) ($result['fiscal_number'] ?? ''), 0, 40) ?: null : null,
            'z_report_number' => mb_substr((string) ($result['z_report_number'] ?? ''), 0, 20) ?: null,
            'printed_at' => $printed ? now() : null,
            'last_error' => $printed ? null : mb_substr((string) ($result['error'] ?? 'Eroare nespecificată'), 0, 1000),
            'updated_at' => now(),
        ]);

        return $receipt->fresh();
    }

    /** Comenzile preluate de agent fără rezultat în intervalul dat devin incerte (rulat din cron). */
    public function markTimeouts(int $minutes = 3): int
    {
        return FiscalReceipt::query()->where('status', 'dispatched')->where('dispatched_at', '<', now()->subMinutes($minutes))
            ->update(['status' => 'uncertain', 'last_error' => 'Fără răspuns de la agent după preluare. Verificați jurnalul casei de marcat înainte de orice reemitere.', 'updated_at' => now()]);
    }

    private function queue(FiscalDevice $device, array $items, array $payments, int $userId, ?Payment $payment, ?FiscalReceipt $retryOf = null): FiscalReceipt
    {
        $total = array_sum(array_map(fn ($item) => (int) $item['amount_minor'], $items));
        $receipt = FiscalReceipt::query()->create([
            'command_id' => (string) Str::uuid(),
            'fiscal_device_id' => $device->id,
            'booking_id' => $payment?->booking_id,
            'payment_id' => $payment?->id,
            'items' => $items,
            'payments' => $payments,
            'total_minor' => $total,
            'is_simulated' => $device->driver === 'simulator',
            'status' => 'queued',
            'retry_of_id' => $retryOf?->id,
            'created_by' => $userId,
        ]);
        if ($device->driver === 'simulator') {
            $receipt->forceFill(['status' => 'printed', 'fiscal_number' => 'SIM-'.str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT), 'printed_at' => now(), 'dispatched_at' => now()])->save();
        }
        $this->audit->log('fiscal.queued', $receipt, ['device' => $device->id, 'total_minor' => $total, 'simulated' => $receipt->is_simulated]);

        return $receipt;
    }

    /** Articolele bonului: detaliat dacă încasarea acoperă integral rezervarea, altfel o linie de avans / rest. */
    private function itemsFor(Payment $payment): array
    {
        $booking = $payment->booking?->loadMissing('items', 'package');
        $date = LocalTime::now();
        if ($booking && $payment->purpose === 'booking' && (int) $booking->discount_minor === 0
            && (int) $booking->items->sum('total_minor') === $payment->amount_minor) {
            return $booking->items->map(fn ($item) => [
                'name' => mb_substr($item->description, 0, 36),
                'quantity' => (string) $item->quantity,
                'unit_price_minor' => (int) $item->unit_price_minor,
                'amount_minor' => (int) $item->total_minor,
                'vat_rate' => $this->vat->rateFor($item->vat_group ?? 'standard', $date)['rate'],
            ])->values()->all();
        }
        $label = match ($payment->purpose) {
            'deposit' => 'Avans rezervare', 'balance' => 'Rest plată rezervare', default => 'Rezervare'
        };

        return [[
            'name' => mb_substr($label.($booking ? ' '.$booking->code : ''), 0, 36),
            'quantity' => '1',
            'unit_price_minor' => $payment->amount_minor,
            'amount_minor' => $payment->amount_minor,
            'vat_rate' => $this->vat->rateFor($booking?->package?->vat_group ?? 'standard', $date)['rate'],
        ]];
    }

    private function vatGroup(FiscalDevice $device, string $rate): ?string
    {
        $mapping = (array) $device->vat_mapping;

        return $mapping[$rate] ?? $mapping[rtrim(rtrim($rate, '0'), '.')] ?? null;
    }
}
