<?php

namespace App\Livewire\Crm;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Requests from the site's forms (CRM-01), scoped to the user's locations. */
class LeadIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $type = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $mine = false;

    public function boot(): void
    {
        $this->authorize('leads.view');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    /** Leads without a location are visible to everyone who handles leads. */
    public static function scoped(): Builder
    {
        $user = auth()->user();
        $locations = app(\App\Support\Access\AccessControl::class)->locationIdsFor($user, 'leads.view');

        return Lead::query()->when($locations !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('location_id')->orWhereIn('location_id', $locations)));
    }

    public function render()
    {
        $term = trim($this->search);
        $leads = self::scoped()->with(['location', 'assignee'])
            ->when($this->status === 'active', fn ($q) => $q->whereIn('status', ['new', 'contacted', 'in_progress']))
            ->when(in_array($this->status, Lead::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->type, Lead::TYPES, true), fn ($q) => $q->where('type', $this->type))
            ->when($this->mine, fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')
                ->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $term).'%')))
            ->orderByRaw("case status when 'new' then 0 when 'contacted' then 1 when 'in_progress' then 2 else 3 end")
            ->latest()->paginate(30);

        return view('livewire.crm.lead-index', [
            'leads' => $leads,
            'counts' => self::scoped()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'tz' => config('optica.clinic_timezone'),
        ])->layout('components.layouts.crm')->title(__('leads.title'));
    }
}
