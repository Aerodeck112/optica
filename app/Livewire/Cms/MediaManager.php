<?php

namespace App\Livewire\Cms;

use App\Domain\Cms\MediaLibrary;
use App\Models\Media;
use App\Support\Audit\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Image library (CMS-03): upload, alternative text in RO and HU, usage check before deletion.
 */
class MediaManager extends Component
{
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $missingAlt = false;

    public array $uploads = [];

    public ?int $selected = null;

    public array $alt = ['ro' => '', 'hu' => ''];

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('media.manage');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedUploads(MediaLibrary $library): void
    {
        $this->validate([
            'uploads' => ['array', 'max:20'],
            'uploads.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=300,min_height=200'],
        ]);
        foreach ($this->uploads as $file) {
            $media = $library->storeUpload($file, [], auth()->id());
            app(AuditLogger::class)->log('cms.media_uploaded', $media);
        }
        $this->notice = trans_choice('cms.media.uploaded', count($this->uploads), ['count' => count($this->uploads)]);
        $this->uploads = [];
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $media = Media::findOrFail($id);
        $this->selected = $media->id;
        $this->alt = ['ro' => (string) ($media->alt['ro'] ?? ''), 'hu' => (string) ($media->alt['hu'] ?? '')];
        $this->resetErrorBag();
    }

    public function saveAlt(): void
    {
        $this->validate(['alt.ro' => ['nullable', 'string', 'max:200'], 'alt.hu' => ['nullable', 'string', 'max:200']]);
        $media = Media::findOrFail($this->selected);
        $media->update(['alt' => ['ro' => trim($this->alt['ro']), 'hu' => trim($this->alt['hu'])]]);
        app(AuditLogger::class)->log('cms.media_updated', $media);
        $this->notice = __('cms.media.saved');
    }

    public function delete(MediaLibrary $library): void
    {
        $media = Media::findOrFail($this->selected);
        if (! $library->delete($media)) {
            $this->addError('delete', __('cms.media.in_use'));

            return;
        }
        app(AuditLogger::class)->log('cms.media_deleted', $media, ['name' => $media->original_name]);
        $this->selected = null;
        $this->notice = __('crm.deleted');
    }

    public function render(MediaLibrary $library)
    {
        $items = Media::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('original_name', 'like', '%'.$this->search.'%')->orWhere('alt', 'like', '%'.$this->search.'%')))
            ->when($this->missingAlt, fn ($q) => $q->where(fn ($q) => $q->whereNull('alt')->orWhere('alt->ro', '')->orWhere('alt->hu', '')))
            ->latest('id')->paginate(30);
        $current = $this->selected ? Media::find($this->selected) : null;

        return view('livewire.cms.media-manager', [
            'items' => $items,
            'current' => $current,
            'usages' => $current ? $library->usages($current) : [],
        ])->layout('components.layouts.crm')->title(__('cms.media.title'));
    }
}
