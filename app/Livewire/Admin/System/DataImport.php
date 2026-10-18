<?php

namespace App\Livewire\Admin\System;

use App\Domain\Import\ImportRunner;
use App\Models\ImportRun;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Sistem → Import (MIG-01…04): exports from Evolvo or another system, patients first, then
 * appointments. Mapping, preview and simulation come before anything is written.
 */
class DataImport extends Component
{
    use WithFileUploads;

    #[Url(as: 'run')]
    public ?string $runUuid = null;

    public string $target = 'patients';

    public string $source = 'evolvo';

    public $file = null;

    public array $mapping = [];

    public bool $notifyFuture = false;

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('system.import');
    }

    public function mount(): void
    {
        if ($run = $this->run()) {
            $this->mapping = array_map('strval', $run->mapping ?? []);
        }
    }

    private function run(): ?ImportRun
    {
        return $this->runUuid ? ImportRun::where('uuid', $this->runUuid)->first() : null;
    }

    public function uploadFile(ImportRunner $runner): void
    {
        $this->validate([
            'target' => ['required', Rule::in(array_keys(ImportRunner::TARGETS))],
            'source' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'file' => ['required', 'file', 'max:51200', 'mimes:csv,txt,xlsx'],
        ], [], ['file' => __('system.import.file'), 'source' => __('system.import.source')]);
        try {
            $run = $runner->upload($this->file->getRealPath(), $this->file->getClientOriginalName(), $this->target, $this->source, auth()->user());
        } catch (Throwable) {
            $this->addError('file', __('system.import.unreadable'));

            return;
        }
        $this->file = null;
        $this->runUuid = $run->uuid;
        $this->mapping = array_map('strval', $run->mapping ?? []);
    }

    public function saveMapping(): void
    {
        $run = $this->run() ?? abort(404);
        abort_unless($run->status === 'uploaded', 422);
        $fields = array_keys(ImportRunner::target($run->target)->fields());
        $clean = [];
        foreach ($this->mapping as $index => $field) {
            if ($field !== '' && in_array($field, $fields, true) && ! in_array($field, $clean, true) && isset($run->headers[(int) $index])) {
                $clean[(int) $index] = $field;
            }
        }
        $run->update(['mapping' => $clean, 'simulation' => null]);
        $this->mapping = array_map('strval', $clean);
        $this->notice = __('system.import.mapping_saved');
    }

    public function simulate(ImportRunner $runner): void
    {
        $run = $this->run() ?? abort(404);
        abort_unless($run->status === 'uploaded' && ! $runner->missingFields($run), 422);
        @set_time_limit(600);
        $runner->simulate($run);
    }

    public function start(ImportRunner $runner): void
    {
        $run = $this->run() ?? abort(404);
        // The simulation is mandatory: nobody imports without having seen its result.
        abort_unless($run->status === 'uploaded' && $run->simulation, 422);
        $runner->start($run, ['notify_future' => $run->target === 'appointments' && $this->notifyFuture]);
    }

    public function batch(ImportRunner $runner): void
    {
        $run = $this->run() ?? abort(404);
        @set_time_limit(300);
        $runner->runBatch($run);
    }

    public function resume(ImportRunner $runner): void
    {
        $run = $this->run() ?? abort(404);
        $runner->resume($run);
    }

    public function close(): void
    {
        $this->runUuid = null;
        $this->mapping = [];
    }

    public function render(ImportRunner $runner)
    {
        $run = $this->run();
        $data = ['run' => $run, 'runs' => ImportRun::with('creator')->latest('id')->limit(15)->get()];
        if ($run) {
            $data['fields'] = ImportRunner::target($run->target)->fields();
            $data['missing'] = $runner->missingFields($run);
            $data['preview'] = $run->status === 'uploaded' && ! $data['missing'] ? $runner->preview($run) : [];
            $data['reconciliation'] = in_array($run->status, ['running', 'done', 'failed'], true) ? $runner->reconciliation($run) : null;
        }

        return view('livewire.admin.system.import', $data)->layout('components.layouts.crm')->title(__('system.import.title'));
    }
}
