<?php

namespace App\Livewire\Admin\Communication;

use App\Domain\Messaging\MessageTypes;
use App\Domain\Messaging\SmsSegments;
use App\Domain\Messaging\TemplateRenderer;
use App\Models\ChannelAccount;
use App\Models\Location;
use App\Models\MessageTemplate;
use App\Support\Audit\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Message templates per type, channel and language (COM-02, TRD-05). Unknown variables are refused,
 * the preview uses fictitious data, SMS length and cost effects are shown (SMS-01), and a WhatsApp
 * text change sends the template back to "draft" until Meta approves it again (WAP-01).
 */
class MessageTemplates extends Component
{
    #[Locked]
    public ?int $editing = null;

    public array $form = [];

    public ?string $notice = null;

    public string $filterType = '';

    public function boot(): void
    {
        $this->authorize('communications.templates');
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->form = ['type' => 'appointment_confirmed', 'channel' => 'sms', 'locale' => 'ro', 'location_id' => null, 'subject' => '', 'body' => '',
            'provider_template' => '', 'provider_status' => 'not_required', 'ascii_only' => false, 'is_active' => true];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $t = MessageTemplate::findOrFail($id);
        $this->editing = $t->id;
        $this->form = $t->only(['type', 'channel', 'locale', 'location_id', 'subject', 'body', 'provider_template', 'provider_status', 'ascii_only', 'is_active']);
        $this->form['subject'] = (string) $this->form['subject'];
        $this->form['provider_template'] = (string) $this->form['provider_template'];
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $f = $this->form;
        $this->validate([
            'form.type' => ['required', Rule::in(MessageTypes::operational())],
            'form.channel' => ['required', Rule::in(ChannelAccount::CHANNELS)],
            'form.locale' => ['required', Rule::in(config('optica.locales'))],
            'form.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'form.subject' => [($f['channel'] ?? '') === 'email' ? 'required' : 'nullable', 'string', 'max:200'],
            'form.body' => ['required', 'string', 'max:'.(($f['channel'] ?? '') === 'sms' ? 1000 : 4000)],
            'form.provider_template' => [($f['channel'] ?? '') === 'whatsapp' ? 'required' : 'nullable', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'form.provider_status' => ['required', Rule::in(MessageTemplate::PROVIDER_STATUSES)],
            'form.ascii_only' => ['boolean'],
            'form.is_active' => ['boolean'],
        ], [], ['form.body' => __('messages.templates.body'), 'form.subject' => __('messages.templates.subject'), 'form.provider_template' => __('messages.templates.provider_template')]);

        $invalid = array_merge(TemplateRenderer::invalid($f['type'], $f['body']), TemplateRenderer::invalid($f['type'], (string) $f['subject']));
        if ($invalid) {
            $this->addError('form.body', __('messages.templates.invalid_variables', ['list' => implode(', ', array_unique($invalid))]));

            return;
        }
        $duplicate = MessageTemplate::where('type', $f['type'])->where('channel', $f['channel'])->where('locale', $f['locale'])
            ->where('location_id', $f['location_id'] ?: null)->where('id', '!=', $this->editing ?: 0)->exists();
        if ($duplicate) {
            $this->addError('form.type', __('validation.unique', ['attribute' => __('messages.templates.type')]));

            return;
        }

        $template = $this->editing ? MessageTemplate::findOrFail($this->editing) : new MessageTemplate;
        $status = $f['channel'] === 'whatsapp' ? $f['provider_status'] : 'not_required';
        // An approved WhatsApp text that changes must be approved again before use.
        if ($f['channel'] === 'whatsapp' && $template->exists && $template->provider_status === 'approved'
            && ($template->body !== $f['body'] || $template->provider_template !== $f['provider_template']) && $status === 'approved') {
            $status = 'draft';
        }
        $template->fill([
            'type' => $f['type'], 'channel' => $f['channel'], 'locale' => $f['locale'], 'location_id' => $f['location_id'] ?: null,
            'subject' => $f['channel'] === 'email' ? $f['subject'] : null,
            'body' => str_replace("\r\n", "\n", $f['body']),
            'provider_template' => $f['channel'] === 'whatsapp' ? $f['provider_template'] : null,
            'provider_status' => $status,
            'ascii_only' => $f['channel'] === 'sms' && $f['ascii_only'],
            'is_active' => $f['is_active'],
            'updated_by' => auth()->id(),
        ])->save();
        app(AuditLogger::class)->log('communications.template_saved', $template, ['type' => $template->type, 'channel' => $template->channel, 'locale' => $template->locale]);
        $this->editing = null;
        $this->notice = __('messages.templates.saved');
    }

    public function render()
    {
        $preview = null;
        $sms = null;
        if ($this->editing !== null && ($this->form['body'] ?? '') !== '') {
            $preview = TemplateRenderer::render($this->form['body'], MessageTypes::sample($this->form['locale'] ?? 'ro'));
            if (($this->form['channel'] ?? '') === 'sms') {
                if ($this->form['ascii_only'] ?? false) {
                    $preview = TemplateRenderer::ascii($preview);
                }
                $sms = SmsSegments::analyse($preview);
            }
        }

        return view('livewire.admin.communication.message-templates', [
            'templates' => MessageTemplate::with('location')->when($this->filterType !== '', fn ($q) => $q->where('type', $this->filterType))
                ->orderBy('type')->orderBy('channel')->orderBy('locale')->get(),
            'locations' => Location::orderBy('sort_order')->get(),
            'variables' => MessageTypes::variables($this->form['type'] ?? 'appointment_confirmed'),
            'invalid' => $this->editing !== null ? TemplateRenderer::invalid($this->form['type'] ?? '', ($this->form['body'] ?? '').' '.($this->form['subject'] ?? '')) : [],
            'preview' => $preview,
            'sms' => $sms,
        ])->layout('components.layouts.crm')->title(__('messages.templates.title'));
    }
}
