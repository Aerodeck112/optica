<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Export files contain personal data: they are kept for 7 days only. */
class PruneReportExports extends Command
{
    protected $signature = 'reports:prune-exports {--days=7}';

    protected $description = 'Deletes report export files older than the retention period';

    public function handle(): int
    {
        $old = ReportExport::where('created_at', '<', now()->subDays((int) $this->option('days')))->get();
        foreach ($old as $export) {
            if ($export->path) {
                Storage::disk('local')->delete($export->path);
            }
            $export->delete();
        }
        $this->info($old->count().' export(s) removed.');

        return self::SUCCESS;
    }
}
