<?php

namespace App\Livewire\Cms;

use App\Models\Post;
use App\Support\Audit\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;

class OfferIndex extends Component
{
    #[Url]
    public bool $showDeleted = false;

    public function boot(): void
    {
        $this->authorize('cms.edit');
    }

    public function restore(int $id): void
    {
        $this->authorize('cms.publish');
        $post = Post::onlyTrashed()->where('type', 'offer')->findOrFail($id);
        $post->restore();
        app(AuditLogger::class)->log('cms.offer_restored', $post);
    }

    public function render()
    {
        return view('livewire.cms.offer-index', [
            'offers' => Post::query()->with('image')->where('type', 'offer')
                ->when($this->showDeleted, fn ($q) => $q->onlyTrashed())
                ->orderByDesc('starts_on')->orderByDesc('id')->get(),
            'today' => now(config('optica.clinic_timezone'))->toDateString(),
        ])->layout('components.layouts.crm')->title(__('cms.offers.title'));
    }
}
