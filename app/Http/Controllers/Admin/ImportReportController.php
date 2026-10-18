<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Import\ImportRunner;
use App\Http\Controllers\Controller;
use App\Models\ImportRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Error and decision report of an import, for review in a spreadsheet (MIG-02, MIG-04). */
class ImportReportController extends Controller
{
    public function __invoke(Request $request, string $uuid): StreamedResponse
    {
        abort_unless($request->user()->can('system.import'), 403);
        $run = ImportRun::where('uuid', $uuid)->firstOrFail();
        $rec = app(ImportRunner::class)->reconciliation($run);

        return response()->streamDownload(function () use ($run, $rec) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('system.import.report_title', ['name' => $run->original_name])], ';');
            fputcsv($out, [__('system.import.rows_in_file'), $rec['rows'], __('system.import.accounted'), $rec['accounted'], __('system.import.in_database'), $rec['in_database']], ';');
            foreach ($rec['stats'] as $outcome => $count) {
                fputcsv($out, [__('system.import.outcomes.'.$outcome), $count], ';');
            }
            fputcsv($out, [], ';');
            fputcsv($out, [__('system.import.row'), __('system.import.outcome'), __('system.import.details')], ';');
            foreach ($run->issues ?? [] as $issue) {
                fputcsv($out, [$issue['row'], __('system.import.outcomes.'.$issue['outcome']), implode(' | ', array_map([self::class, 'message'], $issue['messages']))], ';');
            }
            fclose($out);
        }, 'import-'.$run->target.'-'.$run->created_at->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public static function message(string $code): string
    {
        [$key, $param] = array_pad(explode(':', $code, 2), 2, '');

        return __('system.import.messages.'.$key, ['value' => $param]);
    }
}
