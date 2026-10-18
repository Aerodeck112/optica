<?php

namespace App\Livewire\Cms;

use App\Domain\Cms\MediaLibrary;
use App\Domain\Cms\PagePublisher;
use App\Domain\Cms\Sections;
use App\Models\Lead;
use App\Models\Media;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Redirect;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Page builder (CMS-01, CMS-02): ordered sections with RO/HU text, saved as a draft, previewed,
 * then published as a numbered version. Two editors cannot overwrite each other (lock_version).
 */
class PageEditor extends Component
{
    use WithFileUploads;

    public const TOOLS = ['blur_compare', 'service_guide'];

    public const RESERVED_SLUGS = ['hu', 'crm', 'admin', 'cms', 'livewire', 'api', 'uploads', 'build', 'storage', 'programare', 'previzualizare'];

    #[Locked]
    public int $pageId;

    #[Locked]
    public int $lockVersion;

    public array $draft = [];

    public string $slug_ro = '';

    public string $slug_hu = '';

    public ?int $open = null;

    public string $newType = 'rich_text';

    #[Locked]
    public ?string $pickerTarget = null;

    public string $pickerSearch = '';

    public $upload;

    public ?string $notice = null;

    public bool $conflict = false;

    public function boot(): void
    {
        $this->authorize('cms.edit');
    }

    public function mount(Page $page): void
    {
        $this->pageId = $page->id;
        $this->load($page);
    }

    private function load(Page $page): void
    {
        $this->draft = $this->normalize($page->draft ?? Sections::emptyPage());
        $this->slug_ro = $page->slug_ro;
        $this->slug_hu = $page->slug_hu;
        $this->lockVersion = (int) $page->lock_version;
        $this->conflict = false;
    }

    private function page(): Page
    {
        return Page::findOrFail($this->pageId);
    }

    // ----- sections ---------------------------------------------------------------------------

    public function addSection(): void
    {
        abort_unless(in_array($this->newType, $this->allowedTypes(), true), 422);
        abort_if(count($this->draft['sections']) >= 40, 422);
        $this->draft['sections'][] = Sections::make($this->newType);
        $this->open = count($this->draft['sections']) - 1;
    }

    public function moveSection(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);
        $sections = $this->draft['sections'];
        if (! isset($sections[$index], $sections[$target])) {
            return;
        }
        [$sections[$index], $sections[$target]] = [$sections[$target], $sections[$index]];
        $this->draft['sections'] = $sections;
        $this->open = $this->open === $index ? $target : ($this->open === $target ? $index : $this->open);
    }

    public function removeSection(int $index): void
    {
        if (! isset($this->draft['sections'][$index])) {
            return;
        }
        array_splice($this->draft['sections'], $index, 1);
        $this->open = null;
    }

    public function toggle(int $index): void
    {
        $this->open = $this->open === $index ? null : $index;
    }

    /** Questions are added to both languages at once so RO and HU stay paired. */
    public function addItem(int $index): void
    {
        abort_unless(($this->draft['sections'][$index]['type'] ?? null) === 'faq', 422);
        foreach (config('optica.locales') as $locale) {
            $this->draft['sections'][$index]['text'][$locale]['items'][] = ['q' => '', 'a' => ''];
        }
    }

    public function removeItem(int $index, int $item): void
    {
        foreach (config('optica.locales') as $locale) {
            $items = $this->draft['sections'][$index]['text'][$locale]['items'] ?? [];
            array_splice($items, $item, 1);
            $this->draft['sections'][$index]['text'][$locale]['items'] = $items;
        }
    }

    // ----- images -----------------------------------------------------------------------------

    public function openPicker(string $target): void
    {
        abort_unless($this->validTarget($target), 422);
        $this->pickerTarget = $target;
        $this->pickerSearch = '';
    }

    public function closePicker(): void
    {
        $this->pickerTarget = null;
        $this->upload = null;
    }

    public function pick(int $mediaId): void
    {
        abort_unless($this->pickerTarget && Media::whereKey($mediaId)->exists(), 422);
        $this->assignImage($this->pickerTarget, $mediaId);
        $this->pickerTarget = null;
    }

    public function removeImage(string $target, ?int $mediaId = null): void
    {
        abort_unless($this->validTarget($target), 422);
        if ($target === 'og') {
            $this->draft['og_image_id'] = null;

            return;
        }
        [, $index, $field] = explode('.', $target);
        if ($field === 'image_ids') {
            $this->draft['sections'][$index]['data']['image_ids'] = array_values(array_filter(
                $this->draft['sections'][$index]['data']['image_ids'] ?? [], fn ($id) => (int) $id !== $mediaId));
        } else {
            $this->draft['sections'][$index]['data']['image_id'] = null;
        }
    }

    public function updatedUpload(MediaLibrary $library): void
    {
        $this->authorize('media.manage');
        $this->validate(['upload' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=300,min_height=200']]);
        $media = $library->storeUpload($this->upload, [], auth()->id());
        app(AuditLogger::class)->log('cms.media_uploaded', $media);
        $this->upload = null;
        if ($this->pickerTarget) {
            $this->pick($media->id);
        }
    }

    private function validTarget(string $target): bool
    {
        if ($target === 'og') {
            return true;
        }
        if (! preg_match('/^s\.(\d+)\.(image_id|image_ids)$/', $target, $m)) {
            return false;
        }

        return array_key_exists($m[2], $this->draft['sections'][(int) $m[1]]['data'] ?? []);
    }

    private function assignImage(string $target, int $mediaId): void
    {
        if ($target === 'og') {
            $this->draft['og_image_id'] = $mediaId;

            return;
        }
        [, $index, $field] = explode('.', $target);
        if ($field === 'image_ids') {
            $ids = $this->draft['sections'][$index]['data']['image_ids'] ?? [];
            if (! in_array($mediaId, $ids, true) && count($ids) < 12) {
                $ids[] = $mediaId;
            }
            $this->draft['sections'][$index]['data']['image_ids'] = $ids;
        } else {
            $this->draft['sections'][$index]['data']['image_id'] = $mediaId;
        }
    }

    // ----- saving -----------------------------------------------------------------------------

    protected function rules(): array
    {
        $rules = [
            'draft.title.ro' => ['required', 'string', 'max:160'],
            'draft.sections' => ['array', 'max:40'],
            'draft.sections.*.text.*.heading' => ['nullable', 'string', 'max:300'],
            'draft.sections.*.text.*.body' => ['nullable', 'string', 'max:20000'],
            'draft.sections.*.text.*.button_label' => ['nullable', 'string', 'max:60'],
            'draft.sections.*.text.*.items.*.q' => ['nullable', 'string', 'max:300'],
            'draft.sections.*.text.*.items.*.a' => ['nullable', 'string', 'max:3000'],
            'draft.sections.*.data.button_target' => ['nullable', 'string', 'max:255', 'regex:#^(booking|page:[a-z0-9-]+|/[^\s]*|https?://\S+)$#'],
        ];
        foreach (config('optica.locales') as $locale) {
            $rules["draft.title.$locale"] ??= ['nullable', 'string', 'max:160'];
            $rules["draft.seo.$locale.title"] = ['nullable', 'string', 'max:70'];
            $rules["draft.seo.$locale.description"] = ['nullable', 'string', 'max:300'];
        }
        $page = $this->page();
        if ($page->template !== 'home') {
            $slug = ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(self::RESERVED_SLUGS)];
            $rules['slug_ro'] = [...$slug, Rule::unique('pages', 'slug_ro')->ignore($page->id)];
            $rules['slug_hu'] = [...$slug, Rule::unique('pages', 'slug_hu')->ignore($page->id)];
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        return [
            'draft.title.ro' => mb_strtolower(__('cms.pages.page_title')).' (RO)',
            'draft.title.hu' => mb_strtolower(__('cms.pages.page_title')).' (HU)',
            'slug_ro' => __('cms.pages.slug').' (RO)',
            'slug_hu' => __('cms.pages.slug').' (HU)',
        ];
    }

    public function save(): void
    {
        if ($this->persist()) {
            $this->notice = __('crm.saved_draft');
        }
    }

    public function publish(PagePublisher $publisher): void
    {
        $this->authorize('cms.publish');
        if (! $this->persist()) {
            return;
        }
        $version = $publisher->publish($this->page(), auth()->user());
        $this->notice = __('cms.pages.published_version', ['number' => $version->number]);
    }

    private function persist(): bool
    {
        $this->notice = null;
        $this->validate();
        $page = $this->page();
        $draft = $this->normalize($this->draft);

        // SEO texts and addresses are changed only by people with SEO rights (SEO-01); others keep the stored ones.
        $canSeo = Gate::allows('seo.manage');
        if (! $canSeo) {
            $draft['seo'] = $this->normalize($page->draft ?? [])['seo'];
            $draft['og_image_id'] = $this->normalize($page->draft ?? [])['og_image_id'];
        }
        $slugs = $page->template === 'home' || ! $canSeo
            ? ['slug_ro' => $page->slug_ro, 'slug_hu' => $page->slug_hu]
            : ['slug_ro' => $this->slug_ro, 'slug_hu' => $this->slug_hu];

        $saved = DB::transaction(function () use ($page, $draft, $slugs) {
            $updated = Page::whereKey($page->id)->where('lock_version', $this->lockVersion)->update([
                'draft' => json_encode($draft, JSON_UNESCAPED_UNICODE),
                'slug_ro' => $slugs['slug_ro'],
                'slug_hu' => $slugs['slug_hu'],
                'updated_by' => auth()->id(),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_at' => now(),
            ]);
            if ($updated === 0) {
                return false;
            }
            // A published page that moves keeps its old address working (SEO-02).
            if ($page->published_at) {
                foreach (['ro' => '', 'hu' => '/hu'] as $locale => $prefix) {
                    $old = $page->{'slug_'.$locale};
                    $new = $slugs['slug_'.$locale];
                    if ($old !== $new) {
                        $to = $prefix.'/'.$new;
                        Redirect::where('from_path', Redirect::normalize($to))->delete();
                        Redirect::updateOrCreate(['from_path' => Redirect::normalize($prefix.'/'.$old)], ['to_url' => $to, 'status_code' => 301]);
                        Redirect::where('to_url', $prefix.'/'.$old)->update(['to_url' => $to]);
                    }
                }
            }

            return true;
        });

        if (! $saved) {
            $this->conflict = true;

            return false;
        }

        app(AuditLogger::class)->log('cms.page_saved', $page);
        $this->draft = $draft;
        $this->lockVersion = (int) Page::whereKey($page->id)->value('lock_version');
        $this->slug_ro = $slugs['slug_ro'];
        $this->slug_hu = $slugs['slug_hu'];

        return true;
    }

    /** Throws away local edits and loads what another editor saved. */
    public function reload(): void
    {
        $this->load($this->page());
        $this->open = null;
        $this->notice = null;
    }

    public function discardChanges(): void
    {
        $page = $this->page();
        abort_unless($page->published, 422);
        $page->forceFill(['draft' => $page->published, 'updated_by' => auth()->id(), 'lock_version' => $page->lock_version + 1])->save();
        app(AuditLogger::class)->log('cms.page_draft_discarded', $page);
        $this->load($page);
        $this->notice = __('cms.pages.discarded');
    }

    public function restoreVersion(int $versionId, PagePublisher $publisher): void
    {
        $page = $this->page();
        $publisher->restoreToDraft($page, PageVersion::findOrFail($versionId), auth()->user());
        $this->load($page->refresh());
        $this->notice = __('cms.pages.version_restored');
    }

    public function delete(): void
    {
        $this->authorize('cms.publish');
        $page = $this->page();
        if ($page->is_system) {
            $this->addError('delete', __('cms.pages.system_locked'));

            return;
        }
        DB::transaction(function () use ($page) {
            MenuItem::where('page_id', $page->id)->update(['is_active' => false]);
            $page->delete();
        });
        app(AuditLogger::class)->log('cms.page_deleted', $page);
        session()->flash('status', __('crm.deleted'));
        $this->redirectRoute('cms.pages.index', navigate: true);
    }

    // ----- helpers ----------------------------------------------------------------------------

    /** @return list<string> */
    public function allowedTypes(): array
    {
        return $this->page()->template === 'legal' ? ['rich_text'] : array_keys(Sections::TYPES);
    }

    /**
     * Rebuilds the draft from the section definitions: unknown keys are dropped, values are cast,
     * image references must exist. Nothing the browser sends ends up in the page unchecked.
     */
    private function normalize(array $input): array
    {
        $locales = config('optica.locales');
        $out = Sections::emptyPage();
        foreach ($locales as $locale) {
            $out['title'][$locale] = trim((string) ($input['title'][$locale] ?? ''));
            $seo = $input['seo'][$locale] ?? [];
            $out['seo'][$locale] = [
                'title' => trim((string) ($seo['title'] ?? '')),
                'description' => trim((string) ($seo['description'] ?? '')),
                'noindex' => (bool) ($seo['noindex'] ?? false),
            ];
        }

        $sections = [];
        foreach (array_values((array) ($input['sections'] ?? [])) as $section) {
            $type = $section['type'] ?? null;
            if (! is_string($type) || ! isset(Sections::TYPES[$type])) {
                continue;
            }
            $clean = Sections::make($type);
            if (is_string($section['id'] ?? null) && Str::isUuid($section['id'])) {
                $clean['id'] = $section['id'];
            }
            foreach ($locales as $locale) {
                foreach (Sections::TYPES[$type]['text'] as $field) {
                    $value = $section['text'][$locale][$field] ?? '';
                    $clean['text'][$locale][$field] = $field === 'items'
                        ? array_values(array_map(fn ($i) => ['q' => trim((string) ($i['q'] ?? '')), 'a' => trim((string) ($i['a'] ?? ''))], array_filter((array) $value, 'is_array')))
                        : (is_string($value) ? str_replace("\r\n", "\n", $value) : '');
                }
            }
            foreach (array_keys($clean['data']) as $key) {
                $value = $section['data'][$key] ?? null;
                $clean['data'][$key] = match ($key) {
                    'image_id' => is_numeric($value) ? (int) $value : null,
                    'image_ids' => array_values(array_map('intval', array_filter((array) $value, 'is_numeric'))),
                    'show_phones' => (bool) $value,
                    'image_side' => $value === 'left' ? 'left' : 'right',
                    'lead_type' => in_array($value, Lead::TYPES, true) ? $value : 'contact',
                    'tool' => in_array($value, self::TOOLS, true) ? $value : null,
                    default => is_string($value) && $value !== '' ? trim($value) : null,
                };
            }
            $sections[] = $clean;
        }

        // Only images that exist; one query for the whole page.
        $ids = collect($sections)->flatMap(fn ($s) => array_merge(
            isset($s['data']['image_id']) ? [$s['data']['image_id']] : [], $s['data']['image_ids'] ?? []))
            ->push($input['og_image_id'] ?? null)->filter()->unique();
        $existing = $ids->isEmpty() ? [] : Media::whereIn('id', $ids)->pluck('id')->all();
        foreach ($sections as &$s) {
            if (array_key_exists('image_id', $s['data']) && ! in_array($s['data']['image_id'], $existing, true)) {
                $s['data']['image_id'] = null;
            }
            if (array_key_exists('image_ids', $s['data'])) {
                $s['data']['image_ids'] = array_values(array_filter($s['data']['image_ids'], fn ($id) => in_array($id, $existing, true)));
            }
        }
        unset($s);
        $og = is_numeric($input['og_image_id'] ?? null) ? (int) $input['og_image_id'] : null;
        $out['og_image_id'] = in_array($og, $existing, true) ? $og : null;
        $out['sections'] = $sections;

        return $out;
    }

    public function render()
    {
        $page = $this->page();
        $mediaIds = collect($this->draft['sections'])->flatMap(fn ($s) => array_merge(
            isset($s['data']['image_id']) ? [$s['data']['image_id']] : [], $s['data']['image_ids'] ?? []))
            ->push($this->draft['og_image_id'] ?? null)->filter()->unique();

        return view('livewire.cms.page-editor', [
            'page' => $page,
            'media' => Media::whereIn('id', $mediaIds)->get()->keyBy('id'),
            'library' => $this->pickerTarget
                ? Media::query()->when($this->pickerSearch !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('original_name', 'like', '%'.$this->pickerSearch.'%')->orWhere('alt', 'like', '%'.$this->pickerSearch.'%')))
                    ->latest('id')->limit(60)->get()
                : collect(),
            'versions' => $page->versions()->with('author')->limit(15)->get(),
            'pages' => Page::orderBy('id')->get(['id', 'key', 'draft', 'published']),
            'types' => $this->allowedTypes(),
            'hasChanges' => $page->published === null || $this->normalize($page->published) != $this->draft,
        ])->layout('components.layouts.crm')->title($page->title(published: false));
    }
}
