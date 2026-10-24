<?php

namespace App\Modules\Finance\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Models\Customer;
use App\Modules\Finance\Models\DocumentSeries;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceLine;
use App\Support\LocalTime;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Facturi: ciornă → emitere (număr alocat tranzacțional, instantanee vânzător/cumpărător, sume finale, PDF, hash)
 * → imutabilă. Corecțiile se fac prin factură de stornare. Numerotarea folosește blocarea rândului seriei,
 * deci două emiteri simultane nu pot primi același număr și nu rămân goluri din cauza concurenței.
 */
class InvoiceService
{
    public function __construct(
        private readonly VatCalculator $vat,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ciornă din liniile date. Fiecare linie: description, quantity, unit_gross_minor (prețuri cu TVA) sau unit_net,
     * vat_group (standard/reduced/exempt), opțional item_code, unit_code, advance_invoice_id.
     */
    public function createDraft(array $lines, ?Customer $customer, array $attributes = [], ?int $userId = null): Invoice
    {
        if (! $lines) {
            throw new RuntimeException('Factura trebuie să aibă cel puțin o linie.');
        }

        return DB::transaction(function () use ($lines, $customer, $attributes, $userId) {
            $invoice = Invoice::query()->create($attributes + [
                'document_type' => 'invoice',
                'kind' => 'standard',
                'status' => 'draft',
                'customer_id' => $customer?->id,
                'prices_include_vat' => true,
                'created_by' => $userId,
            ]);
            $this->replaceLines($invoice, $lines);

            return $invoice->refresh();
        });
    }

    /** Ciornă pentru o rezervare: liniile din instantaneul de preț + regularizarea avansurilor facturate. */
    public function draftForBooking(Booking $booking, string $kind = 'standard', ?int $userId = null): Invoice
    {
        $lines = [];
        if ($kind === 'advance') {
            $amount = (int) ($booking->deposit_minor ?: $booking->paid_minor);
            if ($amount <= 0) {
                throw new RuntimeException('Rezervarea nu are avans de facturat.');
            }
            $lines[] = ['description' => "Avans rezervare {$booking->code} — ".$booking->package?->getTranslation('name', 'ro'), 'quantity' => 1, 'unit_gross_minor' => $amount, 'vat_group' => $booking->package?->vat_group ?? 'standard'];
        } else {
            foreach ($booking->items as $item) {
                $lines[] = ['description' => $item->description, 'quantity' => (string) $item->quantity, 'unit_gross_minor' => (int) $item->unit_price_minor, 'vat_group' => $item->vat_group ?? 'standard', 'item_code' => $item->type];
            }
            if ((int) $booking->discount_minor > 0) {
                $lines[] = ['description' => 'Reducere', 'quantity' => 1, 'unit_gross_minor' => -(int) $booking->discount_minor, 'vat_group' => $booking->package?->vat_group ?? 'standard'];
            }
            foreach (Invoice::query()->where('booking_id', $booking->id)->where('kind', 'advance')->where('status', 'issued')->get() as $advance) {
                foreach ($advance->lines as $line) {
                    $lines[] = ['description' => "Regularizare avans factura {$advance->full_number}", 'quantity' => -1, 'unit_gross_minor' => (int) $line->line_gross_minor, 'vat_group' => null, 'vat_rate' => (string) $line->vat_rate, 'vat_category' => $line->vat_category, 'advance_invoice_id' => $advance->id];
                }
            }
        }
        if (! $lines) {
            throw new RuntimeException('Rezervarea nu are linii de preț (cerere fără ofertă).');
        }

        return $this->createDraft($lines, $booking->customer, ['booking_id' => $booking->id, 'kind' => $kind], $userId);
    }

    public function replaceLines(Invoice $invoice, array $lines): void
    {
        if ($invoice->status !== 'draft') {
            throw new RuntimeException('Doar ciornele pot fi modificate.');
        }
        $date = $invoice->issue_date ?? LocalTime::now();
        $prepared = array_map(function (array $line) use ($date) {
            if (! isset($line['vat_rate'])) {
                $vat = $this->vat->rateFor($line['vat_group'] ?? 'standard', $date);
                $line['vat_rate'] = $vat['rate'];
                $line['vat_category'] = $vat['category'];
                $line['vat_exemption_reason'] = $vat['exemption_reason'];
            }

            return $line;
        }, $lines);

        $result = $this->vat->calculate($prepared, (bool) $invoice->prices_include_vat);
        $invoice->lines()->delete();
        foreach ($result['lines'] as $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'position' => $line['position'],
                'description' => mb_substr($line['description'], 0, 255),
                'item_code' => $line['item_code'] ?? null,
                'quantity' => (string) $line['quantity'],
                'unit_code' => $line['unit_code'] ?? 'H87',
                'unit_price' => $line['unit_price'],
                'unit_price_gross_minor' => $line['unit_price_gross_minor'],
                'vat_rate' => $line['vat_rate'],
                'vat_category' => $line['vat_category'],
                'vat_exemption_reason' => $line['vat_exemption_reason'] ?? null,
                'line_net_minor' => $line['line_net_minor'],
                'line_vat_minor' => $line['line_vat_minor'],
                'line_gross_minor' => $line['line_gross_minor'],
                'advance_invoice_id' => $line['advance_invoice_id'] ?? null,
            ]);
        }
        $invoice->forceFill([
            'net_total_minor' => $result['net_minor'],
            'vat_total_minor' => $result['vat_minor'],
            'gross_total_minor' => $result['gross_minor'],
            'rounding_minor' => $result['rounding_minor'],
            'vat_breakdown' => $result['breakdown'],
        ])->save();
    }

    /** @return array<string, mixed> datele vânzătorului din Setări; lipsurile blochează emiterea */
    public function sellerSnapshot(): array
    {
        $required = ['company.legal_name' => 'denumirea', 'company.tax_id' => 'CUI', 'company.reg_no' => 'nr. Reg. Com.', 'company.registered_office' => 'sediul', 'company.vat_payer' => 'regimul de TVA'];
        $missing = array_values(array_filter($required, fn ($label, $key) => $this->settings->filled($key) === null, ARRAY_FILTER_USE_BOTH));
        if ($missing) {
            throw new RuntimeException('Completează în Setări → Date firmă: '.implode(', ', $missing).'.');
        }

        return [
            'name' => $this->settings->filled('company.legal_name'),
            'tax_id' => $this->settings->filled('company.tax_id'),
            'reg_no' => $this->settings->filled('company.reg_no'),
            'address' => $this->settings->filled('company.registered_office'),
            'county_code' => $this->settings->filled('company.county_code'),
            'city' => $this->settings->filled('company.registered_city') ?? $this->settings->filled('venue.address_city'),
            'country' => 'RO',
            'vat_payer' => $this->settings->get('company.vat_payer') === 'yes',
            'vat_on_collection' => $this->settings->get('company.vat_on_collection') === 'yes',
            'share_capital' => $this->settings->filled('company.share_capital'),
            'iban' => $this->settings->filled('company.iban'),
            'bank' => $this->settings->filled('company.bank'),
            'email' => $this->settings->filled('contact.email_billing') ?? $this->settings->filled('contact.email_general'),
            'phone' => $this->settings->filled('contact.phone_main'),
        ];
    }

    public function buyerSnapshot(?Customer $customer, array $override = []): array
    {
        $buyer = [
            'type' => $customer?->type ?? 'person',
            'name' => $customer ? ($customer->type === 'company' && $customer->company_name ? $customer->company_name : $customer->fullName()) : 'Client persoană fizică',
            'tax_id' => $customer?->company_tax_id,
            'reg_no' => $customer?->company_reg_no,
            'address' => $customer?->billing_address,
            'city' => $customer?->billing_city,
            'county' => $customer?->billing_county,
            'country' => $customer?->billing_country ?: 'RO',
            'email' => $customer?->email,
        ];

        return array_replace($buyer, array_filter($override, fn ($v) => $v !== null && $v !== ''));
    }

    public function issue(Invoice $invoice, ?int $userId = null, array $buyerOverride = []): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new RuntimeException('Factura a fost deja emisă.');
        }
        if (! $invoice->lines()->exists()) {
            throw new RuntimeException('Factura nu are linii.');
        }
        $seller = $this->sellerSnapshot();
        $buyer = $this->buyerSnapshot($invoice->customer, $buyerOverride);
        if ($buyer['type'] === 'company' && blank($buyer['tax_id'])) {
            throw new RuntimeException('Pentru o companie este necesar CUI-ul cumpărătorului.');
        }

        $issued = DB::transaction(function () use ($invoice, $userId, $seller, $buyer) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'draft') {
                throw new RuntimeException('Factura a fost deja emisă.');
            }
            $series = DocumentSeries::query()
                ->where('document_type', 'invoice')->where('is_active', true)
                ->when($invoice->series_id, fn ($q) => $q->whereKey($invoice->series_id), fn ($q) => $q->orderByDesc('is_default'))
                ->lockForUpdate()->first();
            if (! $series) {
                throw new RuntimeException('Nu există o serie de facturi activă. Configureaz-o în Financiar → Serii documente.');
            }
            $number = (int) $series->next_number;
            $series->forceFill(['next_number' => $number + 1])->save();

            $today = LocalTime::now()->startOfDay();
            // recalcul final al sumelor (aceleași cote ca în ciornă)
            $lines = $invoice->lines->map(fn (InvoiceLine $l) => [
                'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_gross_minor' => $l->unit_price_gross_minor, 'unit_net' => (string) $l->unit_price,
                'vat_rate' => (string) $l->vat_rate, 'vat_category' => $l->vat_category, 'vat_exemption_reason' => $l->vat_exemption_reason,
                'item_code' => $l->item_code, 'unit_code' => $l->unit_code, 'advance_invoice_id' => $l->advance_invoice_id,
            ])->all();
            $invoice->issue_date = $today;
            $this->replaceLines($invoice, $lines);

            $dueDays = (int) $this->settings->int('finance.invoice_due_days', 0);
            $efactura = $buyer['type'] === 'company' || $this->settings->bool('finance.efactura_b2c');
            $invoice->forceFill([
                'series_id' => $series->id,
                'series_prefix' => $series->prefix,
                'number' => $number,
                'full_number' => $series->prefix.str_pad((string) $number, (int) $series->padding, '0', STR_PAD_LEFT),
                'issue_date' => $today,
                'due_date' => $today->addDays($dueDays),
                'tax_point_date' => $today,
                'seller_snapshot' => $seller,
                'buyer_snapshot' => $buyer,
                'notes' => trim(($invoice->notes ? $invoice->notes."\n" : '').($this->settings->filled('finance.invoice_footer') ?? '')) ?: null,
                'efactura_status' => $efactura && $invoice->document_type !== 'proforma' ? 'pending' : 'not_applicable',
                'status' => 'issued',
                'issued_by' => $userId,
                'issued_at' => now(),
            ])->save();
            $invoice->forceFill(['content_hash' => $this->contentHash($invoice->refresh())]);
            Invoice::withoutEvents(fn () => $invoice->save()); // hash-ul se scrie o singură dată, la emitere

            return $invoice;
        });

        $this->renderPdf($issued);
        if ($issued->booking_id) {
            Booking::query()->whereKey($issued->booking_id)->update(['invoice_status' => 'invoiced']);
        }
        $this->audit->log('invoice.issued', $issued, ['number' => $issued->full_number, 'total' => $issued->gross_total_minor]);

        return $issued;
    }

    /** Factură de stornare (valori negative) pentru o factură emisă; se emite imediat, cu numărul următor din serie. */
    public function storno(Invoice $original, string $reason, ?int $userId = null): Invoice
    {
        if ($original->status !== 'issued' || $original->document_type === 'credit_note') {
            throw new RuntimeException('Se pot storna doar facturi emise.');
        }
        if (Invoice::query()->where('related_invoice_id', $original->id)->where('document_type', 'credit_note')->where('status', 'issued')->exists()) {
            throw new RuntimeException('Factura a fost deja stornată.');
        }
        $lines = $original->lines->map(fn (InvoiceLine $l) => [
            'description' => 'Stornare: '.$l->description, 'quantity' => (string) BigDecimal::of((string) $l->quantity)->negated(),
            'unit_gross_minor' => $l->unit_price_gross_minor, 'unit_net' => (string) $l->unit_price,
            'vat_rate' => (string) $l->vat_rate, 'vat_category' => $l->vat_category, 'vat_exemption_reason' => $l->vat_exemption_reason, 'item_code' => $l->item_code, 'unit_code' => $l->unit_code,
        ])->all();
        $draft = $this->createDraft($lines, $original->customer, [
            'document_type' => 'credit_note', 'related_invoice_id' => $original->id, 'booking_id' => $original->booking_id,
            'prices_include_vat' => $original->prices_include_vat, 'series_id' => $original->series_id,
            'notes' => "Stornează factura {$original->full_number} din ".$original->issue_date->format('d.m.Y').'. Motiv: '.$reason,
        ], $userId);

        return $this->issue($draft, $userId, (array) $original->buyer_snapshot);
    }

    public function contentHash(Invoice $invoice): string
    {
        return hash('sha256', json_encode([
            $invoice->full_number, $invoice->issue_date?->toDateString(), $invoice->seller_snapshot, $invoice->buyer_snapshot,
            $invoice->lines->map(fn ($l) => [$l->description, (string) $l->quantity, (string) $l->unit_price, (string) $l->vat_rate, $l->line_net_minor])->all(),
            $invoice->net_total_minor, $invoice->vat_total_minor, $invoice->gross_total_minor,
        ], JSON_UNESCAPED_UNICODE));
    }

    public function renderPdf(Invoice $invoice): string
    {
        $path = 'invoices/'.$invoice->issue_date->format('Y').'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $invoice->full_number).'.pdf';
        $pdf = Pdf::loadView('finance.invoice-pdf', ['invoice' => $invoice->load('lines', 'relatedInvoice')])->setPaper('a4');
        Storage::disk('local')->put($path, $pdf->output());
        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }
}
