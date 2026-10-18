<?php

namespace App\Livewire\Admin\System;

use App\Domain\System\BackupManager;
use App\Support\Settings;
use Livewire\Component;
use Throwable;

/**
 * Sistem → Backup (OPS-02, UPD-06): verified backups, daily schedule, download, restore with a
 * typed confirmation and an automatic safety backup of the current state.
 */
class SystemBackups extends Component
{
    public bool $scheduleEnabled = false;

    public string $scheduleTime = '02:30';

    public ?string $restoring = null;

    public string $confirmation = '';

    public ?string $notice = null;

    public ?string $error = null;

    public function boot(): void
    {
        $this->authorize('system.backups');
    }

    public function mount(): void
    {
        $schedule = Settings::get('system.backup_schedule', ['enabled' => false, 'time' => '02:30']);
        $this->scheduleEnabled = (bool) ($schedule['enabled'] ?? false);
        $this->scheduleTime = (string) ($schedule['time'] ?? '02:30');
    }

    public function saveSchedule(): void
    {
        $this->validate(['scheduleTime' => ['required', 'date_format:H:i']]);
        Settings::put('system.backup_schedule', ['enabled' => $this->scheduleEnabled, 'time' => $this->scheduleTime], auth()->id());
        $this->notice = __('system.backups.schedule_saved');
    }

    public function create(): void
    {
        @set_time_limit(600);
        $this->reset('notice', 'error');
        try {
            $m = BackupManager::make()->create('manual', auth()->user());
            $this->notice = __('system.backups.created', ['name' => $m['name']]);
        } catch (Throwable $e) {
            $this->error = __('system.backups.failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    public function verify(string $name): void
    {
        $this->reset('notice', 'error');
        BackupManager::make()->verify($name) ? $this->notice = __('system.backups.verified_ok') : $this->error = __('system.backups.verified_bad');
    }

    public function delete(string $name): void
    {
        BackupManager::make()->delete($name);
        $this->notice = __('system.backups.deleted');
    }

    public function askRestore(string $name): void
    {
        abort_unless(BackupManager::make()->manifest($name), 404);
        $this->restoring = $name;
        $this->confirmation = '';
        $this->resetErrorBag();
    }

    public function restore(): void
    {
        abort_unless($this->restoring, 422);
        if (mb_strtoupper(trim($this->confirmation)) !== mb_strtoupper(__('system.backups.confirm_word'))) {
            $this->addError('confirmation', __('system.backups.confirm_mismatch', ['word' => __('system.backups.confirm_word')]));

            return;
        }
        @set_time_limit(900);
        $this->reset('notice', 'error');
        try {
            $safety = BackupManager::make()->restore($this->restoring, auth()->user());
            $this->notice = __('system.backups.restored', ['name' => $this->restoring, 'safety' => $safety['name']]);
        } catch (Throwable $e) {
            $this->error = __('system.backups.failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
        $this->restoring = null;
        $this->confirmation = '';
    }

    public function render()
    {
        return view('livewire.admin.system.backups', ['backups' => BackupManager::make()->all(), 'lastError' => Settings::get('system.last_backup_error')])
            ->layout('components.layouts.crm')->title(__('system.backups.title'));
    }
}
