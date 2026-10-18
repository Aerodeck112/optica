<?php

namespace App\Livewire\Admin\System;

use App\Domain\System\UpdatePackage;
use App\Domain\System\UpdateRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Sistem → Actualizări (UPD-01…06): current and new version, changelog, checks, progress and
 * journal. The update runs in short steps driven by this page (or by `optica:update`).
 */
class SystemUpdates extends Component
{
    use WithFileUploads;

    public $package = null;

    public array $packageErrors = [];

    public ?string $newToken = null;

    public function boot(): void
    {
        $this->authorize('system.updates');
    }

    public function checkPackage(): void
    {
        $this->packageErrors = [];
        $this->validate(['package' => ['required', 'file', 'mimes:zip', 'max:'.(int) (config('optica.updates.max_package_bytes') / 1024)]], [], ['package' => __('system.updates.package')]);
        @set_time_limit(600);
        $result = UpdateRunner::make()->prepare($this->package->getRealPath(), $this->package->getClientOriginalName(), auth()->user());
        $this->packageErrors = $result['errors'];
        $this->package = null;
    }

    public function start(): void
    {
        $journal = UpdateRunner::make()->start(auth()->user());
        // During maintenance only holders of this cookie reach the application.
        Cookie::queue(MaintenanceModeBypassCookie::create($journal['secret']));
    }

    /** One step per request: short requests survive the limits of shared hosting. */
    public function tick(): void
    {
        @set_time_limit(600);
        $runner = UpdateRunner::make();
        if (($runner->journal()['status'] ?? null) === 'running') {
            $journal = $runner->advance();
            if (! empty($journal['secret']) && $journal['status'] === 'running') {
                Cookie::queue(MaintenanceModeBypassCookie::create($journal['secret']));
            }
        }
    }

    public function cancel(): void
    {
        UpdateRunner::make()->cancel(auth()->user());
    }

    public function rollback(): void
    {
        @set_time_limit(900);
        UpdateRunner::make()->rollback(auth()->user());
    }

    public function newRecoveryToken(): void
    {
        $this->newToken = UpdateRunner::make()->kit()->newRecoveryToken();
    }

    public function render()
    {
        $runner = UpdateRunner::make();
        $journal = $runner->journal();
        $stalled = in_array($journal['status'] ?? null, ['running', 'failed'], true)
            && (($journal['status'] ?? null) === 'failed' || now()->diffInSeconds(CarbonImmutable::parse($journal['updated_at'] ?? now())) > 120);

        return view('livewire.admin.system.updates', [
            'version' => $runner->currentVersion(),
            'keys' => count(UpdatePackage::trustedKeys()),
            'journal' => $journal,
            'stalled' => $stalled,
            'history' => DB::table('update_runs')->leftJoin('users', 'users.id', '=', 'update_runs.started_by')
                ->orderByDesc('update_runs.id')->limit(10)->get(['update_runs.*', 'users.name as user_name']),
            'hasRecovery' => is_file($runner->kit()->recoveryHashPath()),
        ])->layout('components.layouts.crm')->title(__('system.updates.title'));
    }
}
