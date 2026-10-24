<?php

namespace App\Modules\Finance\Efactura;

use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\EfacturaSubmission;
use App\Modules\Finance\Models\Invoice;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fluxul e-Factura pentru o factură emisă:
 *   Generat → Validat local → Trimis (index de încărcare) → Acceptat ANAF / Respins.
 * O factură are o singură transmitere „curentă”; nu se retransmite cât timp există una trimisă,
 * în prelucrare sau acceptată. După o respingere se poate genera și transmite o variantă corectată.
 */
class EfacturaService
{
    public function __construct(
        private readonly UblInvoiceBuilder $builder,
        private readonly EfacturaValidator $validator,
        private readonly AnafClient $anaf,
        private readonly AuditLogger $audit,
    ) {}

    public function generate(Invoice $invoice, ?int $userId = null): EfacturaSubmission
    {
        if ($invoice->status !== 'issued' || $invoice->efactura_status === 'not_applicable') {
            throw new RuntimeException('Factura nu se transmite în e-Factura (neemisă sau exceptată).');
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $current = $invoice->submissions()->where('is_current', true)->first();
            if ($current && in_array($current->status, ['uploading', 'uploaded', 'in_processing', 'accepted'], true)) {
                throw new RuntimeException('Factura are deja o transmitere '.(Invoice::EFACTURA_STATUSES[$current->status] ?? $current->status).'; nu se retransmite.');
            }
            $xml = $this->builder->build($invoice);
            $path = 'efactura/'.$invoice->issue_date->format('Y/m').'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $invoice->full_number).'-'.now()->format('YmdHis').'.xml';
            Storage::disk('local')->put($path, $xml);
            $invoice->submissions()->update(['is_current' => false]);
            $errors = $this->validator->validate($invoice, $xml);
            $submission = EfacturaSubmission::query()->create([
                'invoice_id' => $invoice->id,
                'environment' => $this->anaf->environment(),
                'xml_path' => $path,
                'xml_hash' => hash('sha256', $xml),
                'status' => $errors ? 'validation_failed' : 'validated',
                'validation_errors' => $errors ?: null,
                'is_current' => true,
                'created_by' => $userId,
            ]);
            $invoice->forceFill(['efactura_status' => $errors ? 'error' : 'validated'])->save();

            return $submission;
        });
    }

    public function upload(EfacturaSubmission $submission): EfacturaSubmission
    {
        if (! $this->anaf->isAuthorized()) {
            throw new RuntimeException('e-Factura neconfigurată: autorizarea ANAF lipsește. Factura rămâne „Validat local”.');
        }
        // „claim” atomic: o singură încercare de transmitere pentru aceeași variantă
        $claimed = EfacturaSubmission::query()->whereKey($submission->id)->where('status', 'validated')->where('is_current', true)->update(['status' => 'uploading']);
        if (! $claimed) {
            throw new RuntimeException('Transmiterea a fost deja făcută sau varianta nu mai este curentă.');
        }
        $invoice = $submission->invoice;
        $seller = (array) $invoice->seller_snapshot;
        $buyer = (array) $invoice->buyer_snapshot;
        $b2c = blank($buyer['tax_id'] ?? null);
        try {
            $result = $this->anaf->upload(Storage::disk('local')->get($submission->xml_path), UblInvoiceBuilder::digits((string) $seller['tax_id']), $b2c);
        } catch (\Throwable $e) {
            // rezultat incert (ex. timeout după trimitere): nu se retransmite automat; se verifică manual în SPV
            $submission->forceFill(['status' => 'error', 'anaf_errors' => ['Rezultat incert: '.$e->getMessage().' Verificați în SPV înainte de o nouă transmitere.']])->save();
            $invoice->forceFill(['efactura_status' => 'error'])->save();
            throw new RuntimeException('Transmiterea are rezultat incert. Verificați în SPV înainte de a retrimite.');
        }
        $submission->forceFill([
            'status' => $result['ok'] ? 'uploaded' : 'rejected',
            'upload_index' => $result['upload_index'],
            'anaf_errors' => $result['errors'] ?: null,
            'uploaded_at' => now(),
            'finalized_at' => $result['ok'] ? null : now(),
        ])->save();
        $invoice->forceFill(['efactura_status' => $result['ok'] ? 'sent' : 'rejected'])->save();
        $this->audit->log('efactura.uploaded', $invoice, ['index' => $result['upload_index'], 'ok' => $result['ok']]);

        return $submission;
    }

    /** Verifică starea transmiterilor în curs (cron, la 10 minute). */
    public function poll(): int
    {
        if (! $this->anaf->isAuthorized()) {
            return 0;
        }
        $count = 0;
        $pending = EfacturaSubmission::query()->whereIn('status', ['uploaded', 'in_processing'])->where('is_current', true)
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subMinutes(5)))->limit(50)->get();
        foreach ($pending as $submission) {
            $status = $this->anaf->status((string) $submission->upload_index);
            $updates = ['last_checked_at' => now(), 'status_checks' => $submission->status_checks + 1];
            if ($status['state'] === 'ok' || $status['state'] === 'nok') {
                $updates += ['status' => $status['state'] === 'ok' ? 'accepted' : 'rejected', 'download_id' => $status['download_id'], 'finalized_at' => now()];
                if ($status['download_id']) {
                    $zip = $this->anaf->download($status['download_id']);
                    $path = str_replace('.xml', '-raspuns.zip', $submission->xml_path);
                    Storage::disk('local')->put($path, $zip);
                    $updates['response_path'] = $path;
                    $updates['anaf_errors'] = $status['state'] === 'nok' ? ($this->errorsFromZip($zip) ?: $status['errors']) : null;
                }
                $submission->invoice->forceFill(['efactura_status' => $status['state'] === 'ok' ? 'accepted' : 'rejected'])->save();
                $this->audit->as('system')->log('efactura.'.($status['state'] === 'ok' ? 'accepted' : 'rejected'), $submission->invoice);
            } elseif ($status['state'] === 'invalid') {
                $updates += ['status' => 'rejected', 'anaf_errors' => $status['errors'] ?: ['XML cu erori nepreluat de sistem'], 'finalized_at' => now()];
                $submission->invoice->forceFill(['efactura_status' => 'rejected'])->save();
            } else {
                $updates['status'] = 'in_processing';
            }
            $submission->forceFill($updates)->save();
            $count++;
        }

        return $count;
    }

    /** Termenul legal de transmitere (implicit 5 zile lucrătoare de la emitere; nu ține cont de sărbători legale). */
    public function deadline(Invoice $invoice, int $workingDays = 5): CarbonImmutable
    {
        $date = CarbonImmutable::parse($invoice->issue_date);
        $added = 0;
        while ($added < $workingDays) {
            $date = $date->addDay();
            if (! $date->isWeekend()) {
                $added++;
            }
        }

        return $date;
    }

    private function errorsFromZip(string $zip): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'anaf');
        file_put_contents($tmp, $zip);
        $errors = [];
        $archive = new \ZipArchive;
        if ($archive->open($tmp) === true) {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $content = (string) $archive->getFromIndex($i);
                if (preg_match_all('/errorMessage="([^"]+)"/', $content, $m)) {
                    $errors = array_merge($errors, array_map('html_entity_decode', $m[1]));
                }
            }
            $archive->close();
        }
        @unlink($tmp);

        return $errors;
    }

    public static function dueSoon(): Builder
    {
        return Invoice::query()->where('status', 'issued')->whereIn('efactura_status', ['pending', 'validated', 'error', 'rejected'])
            ->where('issue_date', '>=', LocalTime::now()->subDays(30)->toDateString());
    }
}
