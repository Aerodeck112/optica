<?php

namespace App\Http\Controllers\Crm;

use App\Domain\Clinical\ClinicalAccess;
use App\Domain\Documents\DocumentVault;
use App\Http\Controllers\Controller;
use App\Domain\Finance\Ledger;
use App\Models\ClinicalRecord;
use App\Models\Order;
use App\Models\PatientDocument;
use App\Models\Prescription;
use App\Support\Audit\AuditLogger;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Private files and clinical PDFs. Every download is authorised here and journaled; there is no
 * public or guessable URL (MED-04, MED-05, ACC-02).
 */
class PatientFileController extends Controller
{
    public function document(PatientDocument $document, DocumentVault $vault): Response
    {
        abort_unless(Gate::allows('documents.view'), 403);
        $disk = Storage::disk(DocumentVault::DISK);
        abort_unless($disk->exists($document->path), 404);
        app(AuditLogger::class)->log('documents.downloaded', $document, ['patient_id' => $document->patient_id]);

        return $disk->download($document->path, $vault->downloadName($document), [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Record PDF in the chosen language: labels are translated, clinical text stays as written (MED-04). */
    public function recordPdf(Request $request, ClinicalRecord $record, ClinicalAccess $access): Response
    {
        abort_unless($access->canViewRecord($request->user(), $record), 403);
        $locale = $this->locale($request);
        app(AuditLogger::class)->log('medical_records.exported_pdf', $record, ['patient_id' => $record->patient_id, 'locale' => $locale], null, $record->location_id);

        return $this->pdf('pdf.clinical-record', ['record' => $record->load(['patient', 'specialist', 'location', 'template', 'amendments.user'])], $locale,
            'fisa-'.$record->patient->code.'-'.$record->examined_at->format('Ymd'));
    }

    public function prescriptionPdf(Request $request, Prescription $prescription, ClinicalAccess $access): Response
    {
        abort_unless($access->canViewPrescription($request->user(), $prescription), 403);
        $locale = $this->locale($request);
        app(AuditLogger::class)->log('prescriptions.exported_pdf', $prescription, ['patient_id' => $prescription->patient_id, 'locale' => $locale], null, $prescription->location_id);

        return $this->pdf('pdf.prescription', ['rx' => $prescription->load(['patient', 'specialist', 'location', 'author'])], $locale,
            'prescriptie-'.$prescription->patient->code.'-v'.$prescription->version);
    }

    /** Handover document for an order (CMD-03); it is not a fiscal document (FIN-03). */
    public function orderHandover(Request $request, Order $order, Ledger $ledger): Response
    {
        abort_unless($request->user()->can('orders.view', $order->location_id), 403);
        $locale = $this->locale($request, $order->patient?->locale);
        app(AuditLogger::class)->log('orders.handover_pdf', $order, [], null, $order->location_id);

        return $this->pdf('pdf.order-handover', ['order' => $order->load(['patient', 'location', 'items', 'responsible']), 'summary' => $ledger->summary($order)], $locale, 'predare-'.$order->number);
    }

    private function locale(Request $request, ?string $default = null): string
    {
        $locale = (string) $request->query('lang', $default ?? app()->getLocale());

        return in_array($locale, config('optica.locales'), true) ? $locale : config('app.fallback_locale');
    }

    private function pdf(string $view, array $data, string $locale, string $name): Response
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            $logo = public_path('images/logo-optofarm.png');
            $html = view($view, $data + [
                'locale' => $locale,
                'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logo)) : null,
                'phones' => (array) Settings::get('site.booking_phones', []),
                'tz' => config('optica.clinic_timezone'),
            ])->render();
        } finally {
            app()->setLocale($previous);
        }

        return Pdf::loadHTML($html)->setPaper('a4')->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
            ->stream(Str::slug($name).'.pdf', ['Attachment' => false])
            ->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
