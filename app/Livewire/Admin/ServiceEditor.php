<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\GuardsCatalog;
use App\Models\Offering;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ServiceEditor extends Component
{
    use GuardsCatalog;

    private const CONTENT_FIELDS = ['name', 'description', 'preparation'];

    public ?Service $service = null;

    public string $code = '';

    public string $category = 'consultation';

    public int $default_duration = 30;

    public int $buffer_before = 0;

    public int $buffer_after = 0;

    public ?string $default_price = null;

    public string $color = '#c8dbe9';

    public bool $is_active = true;

    public bool $is_public = true;

    public int $sort_order = 0;

    public array $content = [];

    public const CATEGORIES = ['consultation', 'investigation', 'optometry', 'treatment', 'surgery', 'other'];

    public function mount(?Service $service = null): void
    {
        // Fresh copy: column defaults not set on the original instance are loaded too.
        $this->service = $service?->exists ? $service->fresh() : null;
        $this->content = $this->mergeContent(self::CONTENT_FIELDS, $service?->content);
        if ($this->service) {
            $this->fill($this->service->only(['code', 'default_duration', 'buffer_before', 'buffer_after', 'is_active', 'is_public', 'sort_order']));
            $this->category = $this->service->category ?? 'other';
            $this->color = $this->service->color ?? '#c8dbe9';
            $this->default_price = $this->service->default_price !== null ? (string) $this->service->default_price : null;
        }
    }

    protected function rules(): array
    {
        $rules = [
            'code' => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('services', 'code')->ignore($this->service?->id)],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'default_duration' => ['required', 'integer', 'min:5', 'max:480'],
            'buffer_before' => ['integer', 'min:0', 'max:120'],
            'buffer_after' => ['integer', 'min:0', 'max:120'],
            'default_price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
        ];
        foreach (config('optica.locales') as $locale) {
            $rules["content.$locale.name"] = [$locale === config('app.fallback_locale') ? 'required' : 'nullable', 'string', 'max:200'];
            $rules["content.$locale.description"] = ['nullable', 'string', 'max:5000'];
            $rules["content.$locale.preparation"] = ['nullable', 'string', 'max:3000'];
        }

        return $rules;
    }

    public function save(): void
    {
        $data = $this->validate();

        $service = $this->service ?? new Service;
        $service->fill([
            'code' => $data['code'] ?: $this->uniqueCode($this->content['ro']['name'] ?? 'serviciu'),
            'category' => $data['category'],
            'default_duration' => $data['default_duration'],
            'buffer_before' => $data['buffer_before'],
            'buffer_after' => $data['buffer_after'],
            'default_price' => $data['default_price'] !== null && $data['default_price'] !== '' ? $data['default_price'] : null,
            'color' => strtolower($data['color']),
            'is_active' => $data['is_active'],
            'is_public' => $data['is_public'],
            'sort_order' => $data['sort_order'],
            'content' => $this->content,
        ]);
        $created = ! $service->exists;
        $service->save();
        $this->audit($created ? 'catalog.service_created' : 'catalog.service_updated', $service);
        $this->service = $service;

        session()->flash('status', __('crm.saved_draft'));
        $this->redirectRoute('admin.services.edit', $service, navigate: true);
    }

    public function publish(): void
    {
        abort_unless($this->service, 404);
        $this->save();
        $this->service->refresh()->publish();
        $this->audit('catalog.service_published', $this->service);
        session()->flash('status', __('crm.published'));
        $this->redirectRoute('admin.services.edit', $this->service, navigate: true);
    }

    public function delete(): void
    {
        abort_unless($this->service, 404);
        if (($count = $this->futureAppointmentsCount('service_id', $this->service->id)) > 0) {
            $this->addError('delete', trans_choice('crm.delete_blocked_appointments', $count, ['count' => $count]));

            return;
        }
        DB::transaction(function () {
            Offering::where('service_id', $this->service->id)->update(['online_enabled' => false]);
            $this->service->update(['is_public' => false]);
            $this->service->delete();
        });
        $this->audit('catalog.service_deleted', $this->service);
        session()->flash('status', __('crm.deleted'));
        $this->redirectRoute('admin.services.index', navigate: true);
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'serviciu';
        $code = $base;
        for ($i = 2; Service::withTrashed()->where('code', $code)->exists(); $i++) {
            $code = $base.'-'.$i;
        }

        return $code;
    }

    public function render()
    {
        return view('livewire.admin.service-editor')
            ->layout('components.layouts.crm')
            ->title($this->service ? $this->service->name() : __('crm.services.create'));
    }
}
