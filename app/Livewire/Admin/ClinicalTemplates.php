<?php

namespace App\Livewire\Admin;

use App\Domain\Clinical\TemplateSchema;
use App\Models\ClinicalTemplate;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Clinical templates (MED-01): edited as drafts, used only after validation by medical staff.
 * A validated template never changes; a change is a new version, and records keep the version
 * they were written with.
 */
class ClinicalTemplates extends Component
{
    #[Locked]
    public ?int $editing = null;

    public array $name = ['ro' => '', 'hu' => ''];

    public array $fields = [];

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('medical_records.templates');
    }

    public function edit(int $id): void
    {
        $template = ClinicalTemplate::where('status', 'draft')->findOrFail($id);
        $this->editing = $template->id;
        $this->name = ['ro' => (string) ($template->name['ro'] ?? ''), 'hu' => (string) ($template->name['hu'] ?? '')];
        $this->fields = array_map(fn ($f) => array_replace(['key' => '', 'section' => 'other', 'type' => 'text', 'label' => ['ro' => '', 'hu' => ''], 'unit' => '', 'min' => '', 'max' => '', 'step' => '', 'eyes' => false, 'required' => false, 'signed' => false], $f), TemplateSchema::fields($template));
        $this->resetErrorBag();
    }

    public function newVersion(int $id): void
    {
        $source = ClinicalTemplate::findOrFail($id);
        $draft = ClinicalTemplate::where('key', $source->key)->where('status', 'draft')->first();
        if (! $draft) {
            $draft = ClinicalTemplate::create([
                'key' => $source->key,
                'version' => (int) ClinicalTemplate::where('key', $source->key)->max('version') + 1,
                'name' => $source->name,
                'fields' => $source->fields,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
            app(AuditLogger::class)->log('clinical_templates.version_created', $draft, ['version' => $draft->version]);
        }
        $this->edit($draft->id);
    }

    public function addField(): void
    {
        $this->fields[] = ['key' => '', 'section' => 'other', 'type' => 'text', 'label' => ['ro' => '', 'hu' => ''], 'unit' => '', 'min' => '', 'max' => '', 'step' => '', 'eyes' => false, 'required' => false, 'signed' => false];
    }

    public function removeField(int $index): void
    {
        array_splice($this->fields, $index, 1);
    }

    public function moveField(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);
        if (isset($this->fields[$index], $this->fields[$target])) {
            [$this->fields[$index], $this->fields[$target]] = [$this->fields[$target], $this->fields[$index]];
        }
    }

    public function save(): void
    {
        $template = ClinicalTemplate::where('status', 'draft')->findOrFail($this->editing);
        $this->validate([
            'name.ro' => ['required', 'string', 'max:120'],
            'name.hu' => ['nullable', 'string', 'max:120'],
            'fields' => ['required', 'array', 'min:1', 'max:80'],
            'fields.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'fields.*.section' => ['required', Rule::in(TemplateSchema::SECTIONS)],
            'fields.*.type' => ['required', Rule::in(TemplateSchema::TYPES)],
            'fields.*.label.ro' => ['required', 'string', 'max:120'],
            'fields.*.label.hu' => ['nullable', 'string', 'max:120'],
            'fields.*.unit' => ['nullable', 'string', 'max:12'],
            'fields.*.min' => ['nullable', 'numeric', 'required_if:fields.*.type,number'],
            'fields.*.max' => ['nullable', 'numeric', 'required_if:fields.*.type,number', 'gte:fields.*.min'],
            'fields.*.step' => ['nullable', 'numeric', 'gt:0'],
        ], [], ['fields.*.key' => __('admin_templates.key'), 'fields.*.label.ro' => __('admin_templates.label').' (RO)', 'fields.*.min' => 'min', 'fields.*.max' => 'max']);

        $fields = array_map(function ($f) {
            $clean = ['key' => $f['key'], 'section' => $f['section'], 'type' => $f['type'], 'label' => ['ro' => trim($f['label']['ro']), 'hu' => trim((string) $f['label']['hu'])]];
            if ($f['type'] === 'number') {
                $clean += ['min' => (float) $f['min'], 'max' => (float) $f['max']] + ($f['step'] !== '' && $f['step'] !== null ? ['step' => (float) $f['step']] : []);
                if ($f['unit'] !== '') {
                    $clean['unit'] = $f['unit'];
                }
                if ($f['signed']) {
                    $clean['signed'] = true;
                }
            }
            if ($f['eyes'] && in_array($f['type'], ['number', 'line'], true)) {
                $clean['eyes'] = true;
            }
            if ($f['required']) {
                $clean['required'] = true;
            }

            return $clean;
        }, array_values($this->fields));

        $template->update(['name' => $this->name, 'fields' => $fields]);
        app(AuditLogger::class)->log('clinical_templates.saved', $template);
        $this->notice = __('admin_templates.saved');
    }

    /** Validation by medical staff: the previous validated version is retired, records keep theirs. */
    public function approve(int $id): void
    {
        // What is on screen is what gets validated.
        if ($this->editing === $id) {
            $this->save();
        }
        DB::transaction(function () use ($id) {
            $template = ClinicalTemplate::where('status', 'draft')->lockForUpdate()->findOrFail($id);
            ClinicalTemplate::where('key', $template->key)->where('status', 'validated')->update(['status' => 'retired']);
            $template->update(['status' => 'validated', 'validated_by' => auth()->id(), 'validated_at' => now()]);
            app(AuditLogger::class)->log('clinical_templates.validated', $template, ['version' => $template->version]);
        });
        $this->editing = null;
        $this->notice = __('admin_templates.validated');
    }

    public function retire(int $id): void
    {
        $template = ClinicalTemplate::where('status', 'validated')->findOrFail($id);
        $template->update(['status' => 'retired']);
        app(AuditLogger::class)->log('clinical_templates.retired', $template);
    }

    public function render()
    {
        return view('livewire.admin.clinical-templates', [
            'templates' => ClinicalTemplate::with('validator')->orderBy('key')->orderByDesc('version')->get(),
        ])->layout('components.layouts.crm')->title(__('admin_templates.title'));
    }
}
