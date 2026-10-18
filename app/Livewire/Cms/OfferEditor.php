<?php

namespace App\Livewire\Cms;

use App\Models\Media;
use App\Models\Post;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Offers with a validity period: shown on the site only while published and inside the period (CMS-02).
 */
class OfferEditor extends Component
{
    private const FIELDS = ['title', 'summary', 'body'];

    public ?Post $offer = null;

    public array $content = [];

    public string $slug_ro = '';

    public string $slug_hu = '';

    public ?string $starts_on = null;

    public ?string $ends_on = null;

    public ?int $image_id = null;

    public bool $picking = false;

    public function boot(): void
    {
        $this->authorize('cms.edit');
    }

    public function mount(?Post $offer = null): void
    {
        abort_if($offer?->exists && $offer->type !== 'offer', 404);
        $this->offer = $offer?->exists ? $offer : null;
        foreach (config('optica.locales') as $locale) {
            foreach (self::FIELDS as $field) {
                $this->content[$locale][$field] = (string) ($this->offer?->draft[$locale][$field] ?? '');
            }
        }
        if ($this->offer) {
            $this->slug_ro = $this->offer->slug_ro;
            $this->slug_hu = $this->offer->slug_hu;
            $this->starts_on = $this->offer->starts_on?->toDateString();
            $this->ends_on = $this->offer->ends_on?->toDateString();
            $this->image_id = $this->offer->image_id;
        }
    }

    protected function rules(): array
    {
        $slug = ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        $rules = [
            'slug_ro' => [...$slug, Rule::unique('posts', 'slug_ro')->where('type', 'offer')->ignore($this->offer?->id)],
            'slug_hu' => [...$slug, Rule::unique('posts', 'slug_hu')->where('type', 'offer')->ignore($this->offer?->id)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'image_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
        ];
        foreach (config('optica.locales') as $locale) {
            $required = $locale === config('app.fallback_locale') ? 'required' : 'nullable';
            $rules["content.$locale.title"] = [$required, 'string', 'max:160'];
            $rules["content.$locale.summary"] = ['nullable', 'string', 'max:400'];
            $rules["content.$locale.body"] = ['nullable', 'string', 'max:20000'];
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        $labels = [];
        foreach (config('optica.locales') as $locale) {
            foreach (self::FIELDS as $field) {
                $labels["content.$locale.$field"] = mb_strtolower(__('cms.offers.fields.'.$field)).' ('.strtoupper($locale).')';
            }
        }

        return $labels;
    }

    public function save(): void
    {
        $this->persist();
        session()->flash('status', __('crm.saved_draft'));
        $this->redirectRoute('cms.offers.edit', $this->offer, navigate: true);
    }

    public function publish(): void
    {
        $this->authorize('cms.publish');
        $this->persist();
        $this->offer->forceFill(['published' => $this->offer->draft, 'published_at' => now()])->save();
        app(AuditLogger::class)->log('cms.offer_published', $this->offer);
        session()->flash('status', __('crm.published'));
        $this->redirectRoute('cms.offers.edit', $this->offer, navigate: true);
    }

    public function unpublish(): void
    {
        $this->authorize('cms.publish');
        abort_unless($this->offer, 404);
        $this->offer->forceFill(['published_at' => null])->save();
        app(AuditLogger::class)->log('cms.offer_unpublished', $this->offer);
        session()->flash('status', __('cms.offers.unpublished'));
        $this->redirectRoute('cms.offers.edit', $this->offer, navigate: true);
    }

    private function persist(): void
    {
        $this->validate();
        $offer = $this->offer ?? new Post(['type' => 'offer']);
        $offer->fill([
            'slug_ro' => $this->slug_ro ?: $this->uniqueSlug('slug_ro', $this->content['ro']['title']),
            'slug_hu' => $this->slug_hu ?: $this->uniqueSlug('slug_hu', $this->content['hu']['title'] ?: $this->content['ro']['title']),
            'draft' => array_map(fn ($fields) => array_map(fn ($v) => trim(str_replace("\r\n", "\n", (string) $v)), $fields), $this->content),
            'starts_on' => $this->starts_on ?: null,
            'ends_on' => $this->ends_on ?: null,
            'image_id' => $this->image_id,
            'updated_by' => auth()->id(),
        ]);
        $created = ! $offer->exists;
        $offer->save();
        app(AuditLogger::class)->log($created ? 'cms.offer_created' : 'cms.offer_saved', $offer);
        $this->offer = $offer;
    }

    private function uniqueSlug(string $column, string $title): string
    {
        $base = Str::limit(Str::slug($title), 150, '') ?: 'oferta';
        $slug = $base;
        for ($i = 2; Post::withTrashed()->where('type', 'offer')->where($column, $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public function pick(int $id): void
    {
        abort_unless(Media::whereKey($id)->exists(), 422);
        $this->image_id = $id;
        $this->picking = false;
    }

    public function delete(): void
    {
        $this->authorize('cms.publish');
        abort_unless($this->offer, 404);
        $this->offer->delete();
        app(AuditLogger::class)->log('cms.offer_deleted', $this->offer);
        session()->flash('status', __('crm.deleted'));
        $this->redirectRoute('cms.offers.index', navigate: true);
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->offer && $this->offer->draft != $this->offer->published;
    }

    public function render()
    {
        return view('livewire.cms.offer-editor', [
            'image' => $this->image_id ? Media::find($this->image_id) : null,
            'library' => $this->picking ? Media::latest('id')->limit(60)->get() : collect(),
        ])->layout('components.layouts.crm')->title($this->offer ? $this->offer->text('title', null, false) : __('cms.offers.create'));
    }
}
