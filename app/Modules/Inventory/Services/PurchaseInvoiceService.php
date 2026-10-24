<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Efactura\UblInvoiceBuilder;
use App\Modules\Finance\Models\EfacturaInboxMessage;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\Supplier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Facturi de la furnizori: introduse manual sau preluate din e-Factura (SPV). */
class PurchaseInvoiceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<int, array{description:string, quantity:string, unit_price:string, vat_rate:string, item_id?:?int, line_type?:string, unit_code?:string}>  $lines  prețuri fără TVA
     */
    public function register(Supplier $supplier, array $header, array $lines, int $userId, string $source = 'manual', ?int $inboxId = null): PurchaseInvoice
    {
        if (! $lines) {
            throw new InventoryException('Factura trebuie să aibă cel puțin o linie.');
        }
        if (PurchaseInvoice::query()->where('supplier_id', $supplier->id)->where('number', $header['number'])->exists()) {
            throw new InventoryException("Factura {$header['number']} de la {$supplier->name} este deja înregistrată.");
        }

        return DB::transaction(function () use ($supplier, $header, $lines, $userId, $source, $inboxId) {
            $invoice = PurchaseInvoice::query()->create([
                'supplier_id' => $supplier->id,
                'number' => $header['number'],
                'issue_date' => $header['issue_date'],
                'due_date' => $header['due_date'] ?? null,
                'currency' => $header['currency'] ?? 'RON',
                'status' => 'registered',
                'source' => $source,
                'efactura_inbox_id' => $inboxId,
                'notes' => $header['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $this->replaceLines($invoice, $lines, $header['gross_total_minor'] ?? null);
            $this->audit->log('purchase_invoice.registered', $invoice, ['number' => $invoice->number, 'source' => $source]);

            return $invoice->refresh();
        });
    }

    /** Liniile se pot corecta (ex. asocierea cu articole) cât timp nu s-a recepționat nimic. */
    public function replaceLines(PurchaseInvoice $invoice, array $lines, ?int $declaredGross = null): void
    {
        if ($invoice->lines()->where('received_quantity', '>', 0)->exists()) {
            throw new InventoryException('Factura are deja recepții; liniile nu se mai modifică.');
        }
        $invoice->lines()->delete();
        $net = 0;
        $vat = 0;
        foreach (array_values($lines) as $line) {
            $quantity = BigDecimal::of(str_replace(',', '.', (string) $line['quantity']));
            $unit = BigDecimal::of(str_replace(',', '.', (string) $line['unit_price']));
            $rate = BigDecimal::of(str_replace(',', '.', (string) ($line['vat_rate'] ?? '0')));
            $lineNet = $quantity->multipliedBy($unit)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
            $lineVat = BigDecimal::of($lineNet)->multipliedBy($rate)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
            $invoice->lines()->create([
                'item_id' => $line['item_id'] ?? null,
                'description' => mb_substr((string) $line['description'], 0, 255),
                'line_type' => $line['line_type'] ?? 'goods',
                'quantity' => (string) $quantity,
                'unit_code' => $line['unit_code'] ?? 'H87',
                'unit_price' => (string) $unit,
                'vat_rate' => (string) $rate,
                'line_net_minor' => $lineNet,
                'line_vat_minor' => $lineVat,
            ]);
            $net += $lineNet;
            $vat += $lineVat;
        }
        // totalul declarat de furnizor are prioritate (TVA calculat pe cote poate diferi cu bani de suma pe linii)
        $gross = $declaredGross ?? ($net + $vat);
        $invoice->forceFill(['net_total_minor' => $net, 'vat_total_minor' => $gross - $net, 'gross_total_minor' => $gross])->save();
    }

    /** Preia o factură primită prin SPV: furnizorul după CUI (creat dacă lipsește), liniile fără asociere la articole. */
    public function fromInbox(EfacturaInboxMessage $message, int $userId): PurchaseInvoice
    {
        $data = (array) $message->parsed;
        if ($message->purchase_invoice_id) {
            throw new InventoryException('Mesajul a fost deja înregistrat ca factură de achiziție.');
        }
        if (blank($data['number'] ?? null) || blank($data['issue_date'] ?? null)) {
            throw new InventoryException('XML-ul facturii nu a putut fi citit; înregistrați factura manual.');
        }
        $taxId = (string) ($data['supplier_tax_id'] ?? $message->issuer_tax_id);
        $digits = UblInvoiceBuilder::digits($taxId);
        $supplier = Supplier::query()->get()->first(fn (Supplier $s) => $s->tax_id && UblInvoiceBuilder::digits($s->tax_id) === $digits)
            ?? Supplier::query()->create(['name' => $data['supplier_name'] ?: 'Furnizor CUI '.$taxId, 'tax_id' => $taxId, 'country' => 'RO']);

        return DB::transaction(function () use ($message, $data, $supplier, $userId) {
            $invoice = $this->register($supplier, [
                'number' => $data['number'],
                'issue_date' => $data['issue_date'],
                'due_date' => ($data['due_date'] ?? '') ?: null,
                'currency' => ($data['currency'] ?? '') ?: 'RON',
                'gross_total_minor' => isset($data['total']) && $data['total'] !== '' ? BigDecimal::of($data['total'])->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt() : null,
            ], array_map(fn (array $line) => [
                'description' => $line['name'] ?: 'Linie fără denumire',
                'quantity' => $line['quantity'] ?: '1',
                'unit_price' => (string) (BigDecimal::of($line['quantity'] ?: '1')->isZero() ? BigDecimal::zero() : BigDecimal::of($line['net'] ?: '0')->dividedBy($line['quantity'] ?: '1', 4, RoundingMode::HalfUp)),
                'vat_rate' => $line['vat_rate'] ?: '0',
                'unit_code' => $line['unit'] ?: 'H87',
            ], (array) ($data['lines'] ?? [])) ?: [['description' => 'Total factură', 'quantity' => '1', 'unit_price' => $data['net'] ?? '0', 'vat_rate' => '0']], $userId, 'efactura', $message->id);
            $message->forceFill(['purchase_invoice_id' => $invoice->id, 'status' => 'linked'])->save();

            return $invoice;
        });
    }
}
