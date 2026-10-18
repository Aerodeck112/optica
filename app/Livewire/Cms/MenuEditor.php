<?php

namespace App\Livewire\Cms;

use App\Models\MenuItem;
use App\Models\Page;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Header and footer menus. Changes are live immediately, so they need the publish right (CMS-02).
 */
class MenuEditor extends Component
{
    public const MENUS = ['header', 'footer'];

    #[Url]
    public string $menu = 'header';

    public ?int $editing = null;

    public array $form = [];

    public function boot(): void
    {
        $this->authorize('cms.publish');
    }

    public function mount(): void
    {
        $this->menu = in_array($this->menu, self::MENUS, true) ? $this->menu : 'header';
    }

    public function updatedMenu(): void
    {
        $this->menu = in_array($this->menu, self::MENUS, true) ? $this->menu : 'header';
        $this->editing = null;
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->form = ['label' => ['ro' => '', 'hu' => ''], 'kind' => 'page', 'page_id' => null, 'target' => '', 'is_active' => true];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $item = MenuItem::where('menu', $this->menu)->findOrFail($id);
        $this->editing = $item->id;
        $this->form = [
            'label' => ['ro' => (string) ($item->label['ro'] ?? ''), 'hu' => (string) ($item->label['hu'] ?? '')],
            'kind' => $item->page_id ? 'page' : ($item->target === 'booking' ? 'booking' : 'url'),
            'page_id' => $item->page_id,
            'target' => $item->target === 'booking' ? '' : (string) $item->target,
            'is_active' => $item->is_active,
        ];
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.label.ro' => ['required', 'string', 'max:60'],
            'form.label.hu' => ['nullable', 'string', 'max:60'],
            'form.kind' => ['required', Rule::in(['page', 'booking', 'url'])],
            'form.page_id' => ['required_if:form.kind,page', 'nullable', 'integer', Rule::exists('pages', 'id')->whereNull('deleted_at')],
            'form.target' => ['required_if:form.kind,url', 'nullable', 'string', 'max:255', 'regex:#^(/[^\s]*|https?://\S+)$#'],
            'form.is_active' => ['boolean'],
        ], [], [
            'form.label.ro' => __('cms.menus.label').' (RO)',
            'form.label.hu' => __('cms.menus.label').' (HU)',
            'form.page_id' => __('cms.menus.page'),
            'form.target' => __('cms.menus.url'),
        ])['form'];

        $values = [
            'label' => ['ro' => trim($data['label']['ro']), 'hu' => trim((string) $data['label']['hu'])],
            'page_id' => $data['kind'] === 'page' ? $data['page_id'] : null,
            'target' => match ($data['kind']) { 'booking' => 'booking', 'url' => $data['target'], default => null },
            'is_active' => $data['is_active'],
        ];
        if ($this->editing) {
            $item = MenuItem::where('menu', $this->menu)->findOrFail($this->editing);
            $item->update($values);
        } else {
            $item = MenuItem::create($values + ['menu' => $this->menu, 'sort_order' => (int) MenuItem::where('menu', $this->menu)->max('sort_order') + 1]);
        }
        app(AuditLogger::class)->log('cms.menu_saved', $item);
        $this->editing = null;
    }

    public function move(int $id, int $direction): void
    {
        DB::transaction(function () use ($id, $direction) {
            $items = MenuItem::where('menu', $this->menu)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get()->values();
            $index = $items->search(fn ($i) => $i->id === $id);
            $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);
            if ($target === null || ! isset($items[$target])) {
                return;
            }
            $ordered = $items->all();
            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
            foreach ($ordered as $position => $item) {
                $item->update(['sort_order' => $position + 1]);
            }
        });
    }

    public function delete(int $id): void
    {
        $item = MenuItem::where('menu', $this->menu)->findOrFail($id);
        $item->delete();
        app(AuditLogger::class)->log('cms.menu_deleted', $item, ['label' => $item->label('ro')]);
        $this->editing = null;
    }

    public function render()
    {
        return view('livewire.cms.menu-editor', [
            'items' => MenuItem::with('page')->where('menu', $this->menu)->orderBy('sort_order')->orderBy('id')->get(),
            'pages' => Page::orderBy('id')->get(),
        ])->layout('components.layouts.crm')->title(__('cms.menus.title'));
    }
}
