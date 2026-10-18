<?php

namespace App\Livewire\Admin\System;

use App\Domain\System\Diagnostics;
use Livewire\Component;

/** Sistem → Diagnostic (OPS-02). Read-only. */
class SystemDiagnostics extends Component
{
    public function boot(): void
    {
        $this->authorize('system.diagnostics');
    }

    public function render()
    {
        return view('livewire.admin.system.diagnostics', ['checks' => collect(Diagnostics::make()->checks())->groupBy('group')])
            ->layout('components.layouts.crm')->title(__('system.diagnostics.title'));
    }
}
