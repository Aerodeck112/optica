<?php

namespace App\Livewire\Cms;

use App\Domain\Cms\Sections;
use App\Models\Page;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class PageIndex extends Component
{
    #[Url]
    public bool $showDeleted = false;

    public bool $creating = false;

    public string $title_ro = '';

    public string $title_hu = '';

    public string $slug_ro = '';

    public string $slug_hu = '';

    public string $template = 'standard';

    public function boot(): void
    {
        $this->authorize('cms.edit');
    }

    public function updatedTitleRo(string $value): void
    {
        if ($this->slug_ro === '') {
            $this->slug_ro = Str::slug($value);
        }
    }

    public function updatedTitleHu(string $value): void
    {
        if ($this->slug_hu === '') {
            $this->slug_hu = Str::slug($value);
        }
    }

    public function create(): void
    {
        // A new address is an SEO decision (SEO-01).
        $this->authorize('seo.manage');
        $data = $this->validate([
            'title_ro' => ['required', 'string', 'max:160'],
            'title_hu' => ['nullable', 'string', 'max:160'],
            'slug_ro' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'not_in:hu,crm,admin,livewire,api,uploads,build', Rule::unique('pages', 'slug_ro')],
            'slug_hu' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'not_in:crm,admin,livewire,api,uploads,build', Rule::unique('pages', 'slug_hu')],
            'template' => ['required', Rule::in(['standard', 'legal'])],
        ]);

        $key = Str::limit($data['slug_ro'], 50, '');
        for ($i = 2; Page::withTrashed()->where('key', $key)->exists(); $i++) {
            $key = Str::limit($data['slug_ro'], 46, '').'-'.$i;
        }
        $page = Page::create([
            'key' => $key,
            'slug_ro' => $data['slug_ro'],
            'slug_hu' => $data['slug_hu'],
            'template' => $data['template'],
            'draft' => Sections::emptyPage($data['title_ro'], (string) $data['title_hu']),
            'updated_by' => auth()->id(),
        ]);
        app(AuditLogger::class)->log('cms.page_created', $page);

        $this->redirectRoute('cms.pages.edit', $page, navigate: true);
    }

    public function restore(int $id): void
    {
        // Slugs stay reserved while a page is deleted (unique columns), so restoring cannot clash.
        $page = Page::onlyTrashed()->findOrFail($id);
        $page->restore();
        app(AuditLogger::class)->log('cms.page_restored_deleted', $page);
    }

    public function render()
    {
        $pages = Page::query()
            ->when($this->showDeleted, fn ($q) => $q->onlyTrashed())
            ->orderByDesc('is_system')->orderBy('id')
            ->get();

        return view('livewire.cms.page-index', ['pages' => $pages])
            ->layout('components.layouts.crm')->title(__('cms.pages.title'));
    }
}
