<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\GuardsCatalog;
use App\Models\Location;
use App\Models\LocationSpecialist;
use App\Models\Offering;
use App\Models\Specialist;
use App\Support\Media\ImageSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class SpecialistEditor extends Component
{
    use GuardsCatalog, WithFileUploads;

    private const CONTENT_FIELDS = ['title', 'bio', 'specialties'];

    public ?Specialist $specialist = null;

    public string $display_name = '';

    public string $type = 'ophthalmologist';

    public string $code = '';

    public array $languages = ['ro'];

    public bool $is_active = true;

    public bool $is_public = false;

    public int $sort_order = 0;

    public array $content = [];

    /** @var array<int, array{assigned: bool, online: bool}> location id => link state */
    public array $links = [];

    public $photo = null;

    public bool $removePhoto = false;

    public function mount(?Specialist $specialist = null): void
    {
        // Fresh copy: column defaults not set on the original instance are loaded too.
        $this->specialist = $specialist?->exists ? $specialist->fresh() : null;
        $this->content = $this->mergeContent(self::CONTENT_FIELDS, $specialist?->content);

        $existing = $this->specialist
            ? LocationSpecialist::where('specialist_id', $this->specialist->id)->get()->keyBy('location_id')
            : collect();
        foreach (Location::orderBy('sort_order')->pluck('id') as $id) {
            $link = $existing->get($id);
            $this->links[$id] = ['assigned' => (bool) ($link?->is_active), 'online' => (bool) ($link?->online_enabled)];
        }

        if ($this->specialist) {
            $this->fill($this->specialist->only(['display_name', 'type', 'code', 'is_active', 'is_public', 'sort_order']));
            $this->languages = $this->specialist->languages ?? [];
        }
    }

    protected function rules(): array
    {
        $rules = [
            'display_name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(Specialist::TYPES)],
            'code' => ['nullable', 'string', 'max:40', 'alpha_dash', Rule::unique('specialists', 'code')->ignore($this->specialist?->id)],
            'languages' => ['array'],
            'languages.*' => [Rule::in(['ro', 'hu', 'en', 'de'])],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
            'links.*.assigned' => ['boolean'],
            'links.*.online' => ['boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=200'],
        ];
        foreach (config('optica.locales') as $locale) {
            $rules["content.$locale.title"] = ['nullable', 'string', 'max:200'];
            $rules["content.$locale.bio"] = ['nullable', 'string', 'max:8000'];
            $rules["content.$locale.specialties"] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    public function save(): void
    {
        $data = $this->validate();

        DB::transaction(function () use ($data) {
            $specialist = $this->specialist ?? new Specialist;
            $specialist->fill([
                'display_name' => $data['display_name'],
                'type' => $data['type'],
                'code' => $data['code'] ?: $this->uniqueCode($data['display_name']),
                'languages' => array_values($data['languages']),
                'is_active' => $data['is_active'],
                'is_public' => $data['is_public'],
                'sort_order' => $data['sort_order'],
                'content' => $this->content,
            ]);

            if ($this->photo) {
                $old = $specialist->photo_path;
                $specialist->photo_path = app(ImageSanitizer::class)->storePublic($this->photo, 'specialists', 900);
                if ($old) {
                    DB::afterCommit(fn () => Storage::disk('public')->delete($old));
                }
            } elseif ($this->removePhoto && $specialist->photo_path) {
                $old = $specialist->photo_path;
                $specialist->photo_path = null;
                DB::afterCommit(fn () => Storage::disk('public')->delete($old));
            }

            $created = ! $specialist->exists;
            $specialist->save();
            $this->syncLinks($specialist);
            $this->audit($created ? 'catalog.specialist_created' : 'catalog.specialist_updated', $specialist);
            $this->specialist = $specialist;
        });

        session()->flash('status', __('crm.saved_draft'));
        $this->redirectRoute('admin.specialists.edit', $this->specialist, navigate: true);
    }

    public function publish(): void
    {
        abort_unless($this->specialist, 404);
        $this->save();
        $this->specialist->refresh()->publish();
        $this->audit('catalog.specialist_published', $this->specialist);
        session()->flash('status', __('crm.published'));
        $this->redirectRoute('admin.specialists.edit', $this->specialist, navigate: true);
    }

    public function delete(): void
    {
        abort_unless($this->specialist, 404);
        if (($count = $this->futureAppointmentsCount('specialist_id', $this->specialist->id)) > 0) {
            $this->addError('delete', trans_choice('crm.delete_blocked_appointments', $count, ['count' => $count]));

            return;
        }

        DB::transaction(function () {
            // Stops online booking and public listing; history keeps the specialist (ARC-07).
            Offering::where('specialist_id', $this->specialist->id)->update(['online_enabled' => false]);
            LocationSpecialist::where('specialist_id', $this->specialist->id)->update(['online_enabled' => false]);
            $this->specialist->update(['is_public' => false]);
            $this->specialist->delete();
        });
        $this->audit('catalog.specialist_deleted', $this->specialist);
        session()->flash('status', __('crm.deleted'));
        $this->redirectRoute('admin.specialists.index', navigate: true);
    }

    private function syncLinks(Specialist $specialist): void
    {
        $validLocations = Location::pluck('id')->all();
        foreach ($this->links as $locationId => $state) {
            if (! in_array((int) $locationId, $validLocations, true)) {
                continue;
            }
            $link = LocationSpecialist::firstOrNew(['location_id' => (int) $locationId, 'specialist_id' => $specialist->id]);
            if (! $link->exists && ! $state['assigned']) {
                continue;
            }
            $link->is_active = (bool) $state['assigned'];
            $link->online_enabled = $state['assigned'] && $state['online'];
            $link->save();
        }
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name), 32, '') ?: 'specialist';
        $code = $base;
        for ($i = 2; Specialist::withTrashed()->where('code', $code)->exists(); $i++) {
            $code = $base.'-'.$i;
        }

        return $code;
    }

    public function render()
    {
        return view('livewire.admin.specialist-editor', [
            'locations' => Location::orderBy('sort_order')->get(),
        ])->layout('components.layouts.crm')
            ->title($this->specialist ? $this->specialist->display_name : __('crm.specialists.create'));
    }
}
