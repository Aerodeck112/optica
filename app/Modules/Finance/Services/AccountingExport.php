<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FiscalReceipt;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Exporturi pentru contabil: CSV (separator „;”, UTF-8 cu BOM, sume cu virgulă) și arhivă cu PDF-urile facturilor
 * și XML-urile e-Factura. Datele sunt cele emise; nimic nu se recalculează la export.
 */
class AccountingExport
{
    public const TYPES = ['invoices' => 'Facturi emise', 'payments' => 'Încasări și rambursări', 'purchases' => 'Facturi de la furnizori'];

    public function csv(string $type, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $rows = match ($type) {
            'invoices' => $this->invoices($from, $to),
            'payments' => $this->payments($from, $to),
            'purchases' => $this->purchases($from, $to),
        };
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value, $row), ';');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** Arhivă cu PDF-urile facturilor emise și XML-urile transmise în perioadă. Întoarce calea pe disk-ul local. */
    public function documentsZip(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $path = 'exports/documente-'.$from->format('Ymd').'-'.$to->format('Ymd').'-'.now()->format('His').'.zip';
        Storage::disk('local')->makeDirectory('exports');
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($this->issuedQuery($from, $to)->with('submissions')->get() as $invoice) {
            if ($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)) {
                $zip->addFile(Storage::disk('local')->path($invoice->pdf_path), 'facturi/'.basename($invoice->pdf_path));
            }
            $submission = $invoice->submissions->firstWhere('is_current', true);
            if ($submission?->xml_path && Storage::disk('local')->exists($submission->xml_path)) {
                $zip->addFile(Storage::disk('local')->path($submission->xml_path), 'efactura/'.basename($submission->xml_path));
            }
        }
        if ($zip->numFiles === 0) {
            $zip->addFromString('CITESTE.txt', 'Nu există documente emise în perioada selectată.');
        }
        $zip->close();

        return $path;
    }

    private function issuedQuery(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Invoice::query()->where('status', 'issued')->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->orderBy('series_prefix')->orderBy('number');
    }

    private function invoices(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [['Număr', 'Data', 'Tip', 'Cumpărător', 'CUI cumpărător', 'Bază', 'TVA', 'Rotunjire', 'Total', 'Defalcare TVA', 'Factura de referință', 'Rezervare', 'e-Factura', 'Index încărcare ANAF']];
        foreach ($this->issuedQuery($from, $to)->with(['relatedInvoice', 'booking', 'submissions'])->get() as $invoice) {
            $buyer = (array) $invoice->buyer_snapshot;
            $rows[] = [
                $invoice->full_number,
                $invoice->issue_date->format('d.m.Y'),
                (Invoice::TYPES[$invoice->document_type] ?? $invoice->document_type).($invoice->kind === 'advance' ? ' (avans)' : ''),
                $buyer['name'] ?? '',
                $buyer['tax_id'] ?? '',
                self::amount($invoice->net_total_minor),
                self::amount($invoice->vat_total_minor),
                self::amount((int) $invoice->rounding_minor),
                self::amount($invoice->gross_total_minor),
                implode(' | ', array_map(fn ($row) => rtrim(rtrim((string) $row['rate'], '0'), '.').'% '.$row['category'].': '.self::amount($row['taxable_minor']).' / '.self::amount($row['tax_minor']), (array) $invoice->vat_breakdown)),
                $invoice->relatedInvoice?->full_number ?? '',
                $invoice->booking?->code ?? '',
                Invoice::EFACTURA_STATUSES[$invoice->efactura_status] ?? $invoice->efactura_status,
                $invoice->submissions->firstWhere('is_current', true)?->upload_index ?? '',
            ];
        }

        return $rows;
    }

    private function payments(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = LocalTime::fromLocal($from->toDateString(), '00:00');
        $end = LocalTime::fromLocal($to->toDateString(), '00:00')->addDay();
        $rows = [['Data', 'Rezervare', 'Client', 'Metodă', 'Sumă', 'Rambursat', 'Net', 'Stare', 'Procesator', 'Referință procesator', 'Bon fiscal', 'Bon simulat', 'Notă']];
        $payments = Payment::query()->whereIn('status', ['succeeded', 'refunded', 'partially_refunded'])->whereBetween('succeeded_at', [$start, $end])->with(['booking', 'customer'])->orderBy('succeeded_at')->get();
        $receipts = FiscalReceipt::query()->whereIn('payment_id', $payments->pluck('id'))->whereIn('status', ['printed', 'reconciled_printed'])->get()->keyBy('payment_id');
        foreach ($payments as $payment) {
            $receipt = $receipts[$payment->id] ?? null;
            $rows[] = [
                LocalTime::format($payment->succeeded_at, 'd.m.Y H:i'),
                $payment->booking?->code ?? '',
                $payment->customer?->fullName() ?? '',
                Payment::METHODS[$payment->method] ?? $payment->method,
                self::amount($payment->amount_minor),
                self::amount($payment->refunded_minor),
                self::amount($payment->netMinor()),
                Payment::STATUSES[$payment->status] ?? $payment->status,
                $payment->provider ?? '',
                $payment->provider_reference ?? '',
                $receipt?->fiscal_number ?? '',
                $receipt?->is_simulated ? 'DA — nu este bon fiscal' : '',
                $payment->note ?? '',
            ];
        }

        return $rows;
    }

    private function purchases(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [['Furnizor', 'CUI', 'Număr', 'Data', 'Scadență', 'Bază', 'TVA', 'Total', 'Stare', 'Sursă']];
        foreach (PurchaseInvoice::query()->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->with('supplier')->orderBy('issue_date')->get() as $invoice) {
            $rows[] = [
                $invoice->supplier?->name ?? '',
                $invoice->supplier?->tax_id ?? '',
                $invoice->number,
                $invoice->issue_date->format('d.m.Y'),
                $invoice->due_date?->format('d.m.Y') ?? '',
                self::amount($invoice->net_total_minor),
                self::amount($invoice->vat_total_minor),
                self::amount($invoice->gross_total_minor),
                $invoice->status,
                $invoice->source === 'efactura' ? 'e-Factura (SPV)' : 'Manual',
            ];
        }

        return $rows;
    }

    private static function amount(int $minor): string
    {
        return number_format($minor / 100, 2, ',', '');
    }
}
