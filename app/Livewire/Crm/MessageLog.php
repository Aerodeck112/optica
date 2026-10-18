<?php

namespace App\Livewire\Crm;

use App\Models\Message;
use App\Support\Access\AccessControl;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Message history with real provider states (COM-02). A failed or unconfirmed message is resent
 * only by a person, as a new message linked to the original (COM-03).
 */
class MessageLog extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $patientId = null;

    #[Url]
    public string $status = '';

    #[Url]
    public string $channel = '';

    #[Url]
    public string $type = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Locked]
    public ?int $open = null;

    public function boot(): void
    {
        $this->authorize('communications.view_log');
    }

    public function mount(?int $patientId = null): void
    {
        $this->patientId = $patientId;
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    private function scoped(): Builder
    {
        $locations = app(AccessControl::class)->locationIdsFor(auth()->user(), 'communications.view_log');

        return Message::query()
            ->when($this->patientId, fn ($q) => $q->where('patient_id', $this->patientId))
            ->when($locations !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('location_id')->orWhereIn('location_id', $locations)));
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $this->scoped()->findOrFail($id)->id;
    }

    public function retry(int $id): void
    {
        $this->authorize('communications.send');
        $original = $this->scoped()->whereIn('status', ['failed', 'uncertain'])->where('category', '!=', 'marketing')->findOrFail($id);
        $copy = $original->replicate(['uuid', 'status', 'status_reason', 'provider_message_id', 'attempts', 'next_attempt_at', 'accepted_at', 'sent_at',
            'delivered_at', 'failed_at', 'cost_reported_cents', 'idempotency_key', 'created_by']);
        $copy->fill(['uuid' => (string) Str::uuid(), 'status' => 'pending', 'attempts' => 0, 'send_after' => now(), 'fallback_of_id' => $original->id, 'created_by' => auth()->id()])->save();
        app(AuditLogger::class)->log('communications.resent', $copy, ['original' => $original->id]);
    }

    public function cancel(int $id): void
    {
        $this->authorize('communications.send');
        $this->scoped()->where('status', 'pending')->whereKey($id)->update(['status' => 'cancelled', 'status_reason' => 'cancelled_by_staff', 'updated_at' => now()]);
    }

    public function render()
    {
        $term = trim($this->search);
        $messages = $this->scoped()->with(['patient', 'events'])
            ->when(in_array($this->status, Message::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->when($this->channel !== '', fn ($q) => $q->where('channel', $this->channel))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($term !== '', fn ($q) => $q->where('recipient', 'like', '%'.$term.'%'))
            ->latest('id')->paginate(30);

        $view = view('livewire.crm.message-log', ['messages' => $messages, 'tz' => config('optica.clinic_timezone')]);

        return $this->patientId ? $view : $view->layout('components.layouts.crm')->title(__('messages.log.title'));
    }
}
