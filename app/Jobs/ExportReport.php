<?php

namespace App\Jobs;

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportTables;
use App\Domain\Reports\ReportWriter;
use App\Models\ReportExport;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds an export in the background (RAP-05). The file is private, readable only by its author
 * through the download controller. Rights are checked again when the job runs.
 */
class ExportReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportTables $tables, ReportWriter $writer, ReportFilterDescriber $describer): void
    {
        $export = ReportExport::with('user')->find($this->exportId);
        if (! $export || $export->status !== 'queued') {
            return;
        }
        $user = $export->user;
        $allowed = $user && $user->can('reports.export') && (! in_array($export->report, ReportTables::FINANCIAL, true) || $user->can('reports.financial'));
        if (! $allowed) {
            $export->forceFill(['status' => 'failed', 'error' => 'forbidden', 'finished_at' => now()])->save();

            return;
        }
        $export->forceFill(['status' => 'running'])->save();
        try {
            $filters = ReportFilters::fromArray($export->filters);
            $table = $tables->build($export->report, $filters, $user, $export->locale);
            $relative = 'exports/'.$export->uuid.'.'.$export->format;
            Storage::disk('local')->makeDirectory('exports');
            $writer->write($table, $export->format, Storage::disk('local')->path($relative), $describer->lines($filters, $export->locale), $export->locale);
            $export->forceFill(['status' => 'done', 'path' => $relative, 'rows' => count($table['rows']), 'finished_at' => now()])->save();
            app(AuditLogger::class)->log('reports.exported', $export, ['report' => $export->report, 'format' => $export->format, 'rows' => count($table['rows'])], $user);
        } catch (Throwable $e) {
            report($e);
            $export->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()])->save();
        }
    }
}
