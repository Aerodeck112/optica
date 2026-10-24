<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\EfacturaSubmission;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Descărcări de documente financiare din disk-ul privat (nu sunt publice). */
class FinanceDocumentController extends Controller
{
    public function invoicePdf(Request $request, Invoice $invoice, InvoiceService $invoices): StreamedResponse
    {
        abort_unless($request->user()?->can('finance.view') || $request->user()?->can('finance.issue'), 403);
        abort_unless($invoice->status === 'issued', 404);
        if (! $invoice->pdf_path || ! Storage::disk('local')->exists($invoice->pdf_path)) {
            $invoices->renderPdf($invoice);
        }
        app(AuditLogger::class)->log('invoice.downloaded', $invoice);

        return Storage::disk('local')->download($invoice->pdf_path, basename($invoice->pdf_path), ['Cache-Control' => 'no-store']);
    }

    public function efacturaFile(Request $request, EfacturaSubmission $submission, string $kind): StreamedResponse
    {
        abort_unless($request->user()?->can('efactura.manage') || $request->user()?->can('finance.view'), 403);
        $path = $kind === 'response' ? $submission->response_path : $submission->xml_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path), ['Cache-Control' => 'no-store']);
    }
}
