<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\GuardsCatalog;
use App\Models\Location;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class LocationEditor extends Component
{
    use GuardsCatalog;

    private const CONTENT_FIELDS = ['name', 'city', 'address', 'description'];

    public ?Location $location = null;

    public string $code = '';

    public string $city = '';

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    public ?string $latitude = null;

    public ?string $longitude = null;

    public bool $is_active = true;

    public bool $is_public = true;

    public bool $online_booking_enabled = false;

    public string $confirmation_mode = 'reception';

    public int $sort_order = 0;

    public array $content = [];

    /** @var array<int, array{closed: bool, open: string, close: string}> ISO weekday => hours */
    public array $hours = [];

    public function mount(?Location $location = null): void
    {
        // Fresh copy: column defaults not set on the original instance are loaded too.
        $this->location = $location?->exists ? $location->fresh() : null;
        $this->content = $this->mergeContent(self::CONTENT_FIELDS, $location?->content);

        foreach (range(1, 7) as $day) {
            $range = $location?->opening_hours[$day][0] ?? null;
            $this->hours[$day] = ['closed' => $range === null, 'open' => $range[0] ?? '08:00', 'close' => $range[1] ?? '18:00'];
        }

        if ($this->location) {
            $this->fill($this->location->only([
                'code', 'city', 'address', 'is_active', 'is_public', 'online_booking_enabled', 'confirmation_mode', 'sort_order',
            ]));
            $this->phone = (string) $this->location->phone;
            $this->email = (string) $this->location->email;
            $this->latitude = $this->location->latitude !== null ? (string) $this->location->latitude : null;
            $this->longitude = $this->location->longitude !== null ? (string) $this->location->longitude : null;
        }
    }

    protected function rules(): array
    {
        $rules = [
            'code' => ['nullable', 'string', 'max:40', 'alpha_dash', Rule::unique('locations', 'code')->ignore($this->location?->id)],
            'city' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9 +().-]+$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'online_booking_enabled' => ['boolean'],
            'confirmation_mode' => ['required', Rule::in(['reception', 'automatic'])],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
            'hours.*.closed' => ['boolean'],
            'hours.*.open' => ['required_if:hours.*.closed,false', 'nullable', 'date_format:H:i'],
            'hours.*.close' => ['required_if:hours.*.closed,false', 'nullable', 'date_format:H:i'],
        ];
        foreach (config('optica.locales') as $locale) {
            $rules["content.$locale.name"] = [$locale === config('app.fallback_locale') ? 'required' : 'nullable', 'string', 'max:200'];
            $rules["content.$locale.city"] = ['nullable', 'string', 'max:120'];
            $rules["content.$locale.address"] = ['nullable', 'string', 'max:255'];
            $rules["content.$locale.description"] = ['nullable', 'string', 'max:5000'];
        }

        return $rules;
    }

    public function save(): void
    {
        $data = $this->validate();
        foreach ($this->hours as $day => $h) {
            if (! $h['closed'] && $h['open'] >= $h['close']) {
                $this->addError("hours.$day.close", __('crm.locations.hours_invalid'));

                return;
            }
        }

        $location = $this->location ?? new Location;
        $location->fill([
            'code' => $data['code'] ?: Str::slug($data['city'].' '.$data['address']),
            'city' => $data['city'],
            'address' => $data['address'],
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'latitude' => $data['latitude'] ?: null,
            'longitude' => $data['longitude'] ?: null,
            'is_active' => $data['is_active'],
            'is_public' => $data['is_public'],
            'online_booking_enabled' => $data['online_booking_enabled'],
            'confirmation_mode' => $data['confirmation_mode'],
            'sort_order' => $data['sort_order'],
            'content' => $this->content,
            'opening_hours' => collect($this->hours)->map(fn ($h) => $h['closed'] ? [] : [[$h['open'], $h['close']]])->all(),
        ]);
        $created = ! $location->exists;
        $location->save();
        $this->audit($created ? 'catalog.location_created' : 'catalog.location_updated', $location);

        session()->flash('status', __('crm.saved_draft'));
        $this->redirectRoute('admin.locations.edit', $location, navigate: true);
    }

    public function publish(): void
    {
        abort_unless($this->location && auth()->user()->can('catalog.manage'), 403);
        $this->save();
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $this->location->refresh()->publish();
        $this->audit('catalog.location_published', $this->location);
        session()->flash('status', __('crm.published'));
        $this->redirectRoute('admin.locations.edit', $this->location, navigate: true);
    }

    public function delete(): void
    {
        abort_unless($this->location, 404);
        if (($count = $this->futureAppointmentsCount('location_id', $this->location->id)) > 0) {
            $this->addError('delete', trans_choice('crm.delete_blocked_appointments', $count, ['count' => $count]));

            return;
        }
        $this->location->update(['is_public' => false, 'online_booking_enabled' => false]);
        $this->location->delete();
        $this->audit('catalog.location_deleted', $this->location);
        session()->flash('status', __('crm.deleted'));
        $this->redirectRoute('admin.locations.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.location-editor')
            ->layout('components.layouts.crm')
            ->title($this->location ? $this->location->name() : __('crm.locations.create'));
    }
}
