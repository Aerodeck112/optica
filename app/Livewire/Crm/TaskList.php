<?php

namespace App\Livewire\Crm;

use App\Models\Task;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Internal tasks (CRM-02, PAC-03). Embedded in a patient or lead it lists that subject's tasks;
 * as a page it lists the user's tasks or those of their locations.
 */
class TaskList extends Component
{
    #[Locked]
    public ?string $subjectType = null;

    #[Locked]
    public ?int $subjectId = null;

    #[Url]
    public string $scope = 'mine';

    #[Url]
    public string $status = 'open';

    public bool $creating = false;

    public array $form = ['title' => '', 'description' => '', 'due_at' => '', 'assigned_to' => null, 'is_urgent' => false];

    public function boot(): void
    {
        $this->authorize('tasks.manage');
    }

    public function mount(?string $subjectType = null, ?int $subjectId = null): void
    {
        abort_if($subjectType !== null && ! isset(Task::SUBJECTS[$subjectType]), 404);
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        if ($subjectType) {
            Task::SUBJECTS[$subjectType]::findOrFail($subjectId);
        }
    }

    public function create(): void
    {
        $this->creating = true;
        $this->form = ['title' => '', 'description' => '', 'due_at' => '', 'assigned_to' => auth()->id(), 'is_urgent' => false];
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.title' => ['required', 'string', 'max:200'],
            'form.description' => ['nullable', 'string', 'max:3000'],
            'form.due_at' => ['nullable', 'date'],
            'form.assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'form.is_urgent' => ['boolean'],
        ], [], ['form.title' => __('tasks.fields.title'), 'form.due_at' => __('tasks.fields.due_at'), 'form.assigned_to' => __('tasks.fields.assigned_to')])['form'];

        $task = Task::create([
            'title' => trim($data['title']),
            'description' => $data['description'] ?: null,
            // Entered in the clinic's time, stored in UTC (D-003).
            'due_at' => $data['due_at'] ? \Carbon\CarbonImmutable::parse($data['due_at'], config('optica.clinic_timezone'))->utc() : null,
            'assigned_to' => $data['assigned_to'],
            'is_urgent' => $data['is_urgent'],
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'location_id' => auth()->user()->locations()->value('locations.id'),
            'created_by' => auth()->id(),
        ]);
        app(AuditLogger::class)->log('tasks.created', $task);
        $this->creating = false;
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, Task::STATUSES, true), 422);
        $task = $this->query()->findOrFail($id);
        $task->update([
            'status' => $status,
            'completed_at' => $status === 'done' ? now() : null,
            'completed_by' => $status === 'done' ? auth()->id() : null,
        ]);
        app(AuditLogger::class)->log('tasks.'.$status, $task);
    }

    /** Tasks this user may act on. */
    private function query(): Builder
    {
        $user = auth()->user();
        $query = Task::query();
        if ($this->subjectType) {
            return $query->where('subject_type', $this->subjectType)->where('subject_id', $this->subjectId);
        }
        if ($this->scope === 'mine' || ! $user->all_locations && $user->locations()->doesntExist()) {
            return $query->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id));
        }

        return $user->all_locations ? $query : $query->where(fn ($q) => $q
            ->whereIn('location_id', $user->locations()->pluck('locations.id'))
            ->orWhere('assigned_to', $user->id)->orWhere('created_by', $user->id));
    }

    public function render()
    {
        $tasks = $this->query()->with(['assignee', 'subject'])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status === 'done' ? 'done' : 'open'))
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderByDesc('is_urgent')->orderByDesc('id')
            ->limit(200)->get();

        $view = view('livewire.crm.task-list', [
            'tasks' => $tasks,
            'users' => $this->creating ? User::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'tz' => config('optica.clinic_timezone'),
        ]);

        return $this->subjectType ? $view : $view->layout('components.layouts.crm')->title(__('tasks.title'));
    }
}
