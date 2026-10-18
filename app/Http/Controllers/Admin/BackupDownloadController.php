<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\BackupManager;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** A backup holds every patient record: downloading it is a permission of its own and is logged. */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, string $name): BinaryFileResponse
    {
        abort_unless($request->user()->can('system.backups'), 403);
        $backups = BackupManager::make();
        abort_unless($backups->manifest($name) !== null, 404);
        app(AuditLogger::class)->log('system.backup_downloaded', null, ['name' => $name]);

        return response()->download($backups->archive($name), 'optica-backup-'.$name.'.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }
}
