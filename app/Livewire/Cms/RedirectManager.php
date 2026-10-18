<?php

namespace App\Livewire\Cms;

use App\Models\NotFoundLog;
use App\Models\Redirect;
use App\Support\Audit\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Redirect manager and the 404 report (SEO-02): old addresses keep working, missing ones are visible.
 */
class RedirectManager extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'redirects';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $editing = null;

    public string $from_path = '';

    public string $to_url = '';

    public int $status_code = 301;

    public function boot(): void
    {
        $this->authorize('seo.manage');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTab(): void
    {
        $this->tab = $this->tab === 'not_found' ? 'not_found' : 'redirects';
        $this->resetPage();
        $this->editing = null;
    }

    public function create(?string $from = null): void
    {
        $this->tab = 'redirects';
        $this->editing = 0;
        $this->from_path = $from ?? '';
        $this->to_url = '';
        $this->status_code = 301;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $redirect = Redirect::findOrFail($id);
        $this->editing = $redirect->id;
        $this->from_path = $redirect->from_path;
        $this->to_url = (string) $redirect->to_url;
        $this->status_code = (int) $redirect->status_code;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->from_path = Redirect::normalize($this->from_path);
        $this->to_url = trim($this->to_url);
        $this->validate([
            'from_path' => ['required', 'string', 'max:255', 'not_in:/', Rule::unique('redirects', 'from_path')->ignore($this->editing ?: null)],
            'status_code' => ['required', Rule::in([301, 302, 410])],
            'to_url' => [Rule::requiredIf($this->status_code !== 410), 'nullable', 'string', 'max:500', 'regex:#^(/[^\s]*|https?://\S+)$#'],
        ], [], ['from_path' => __('cms.redirects.from'), 'to_url' => __('cms.redirects.to')]);

        // A redirect must not point back to itself or into a chain that returns here.
        if ($this->status_code !== 410 && $this->loops($this->from_path, $this->to_url)) {
            $this->addError('to_url', __('cms.redirects.loop'));

            return;
        }

        $values = ['from_path' => $this->from_path, 'to_url' => $this->status_code === 410 ? null : $this->to_url, 'status_code' => $this->status_code];
        $redirect = $this->editing ? tap(Redirect::findOrFail($this->editing))->update($values) : Redirect::create($values);
        NotFoundLog::where('path', $this->from_path)->delete();
        app(AuditLogger::class)->log('seo.redirect_saved', $redirect, ['from' => $redirect->from_path]);
        $this->editing = null;
    }

    private function loops(string $from, string $to): bool
    {
        $seen = [$from => true];
        $next = $to;
        for ($i = 0; $i < 10; $i++) {
            if (preg_match('#^https?://#', $next)) {
                $host = parse_url($next, PHP_URL_HOST);
                if ($host !== parse_url(url('/'), PHP_URL_HOST)) {
                    return false;
                }
                $next = (string) parse_url($next, PHP_URL_PATH);
            }
            $path = Redirect::normalize($next);
            if (isset($seen[$path])) {
                return true;
            }
            $seen[$path] = true;
            $hop = Redirect::where('from_path', $path)->where('id', '!=', $this->editing ?: 0)->first();
            if (! $hop || ! $hop->to_url) {
                return false;
            }
            $next = $hop->to_url;
        }

        return true;
    }

    public function delete(int $id): void
    {
        $redirect = Redirect::findOrFail($id);
        $redirect->delete();
        app(AuditLogger::class)->log('seo.redirect_deleted', $redirect, ['from' => $redirect->from_path]);
    }

    public function dismiss(int $id): void
    {
        NotFoundLog::whereKey($id)->delete();
    }

    public function render()
    {
        $term = mb_strtolower(trim($this->search));
        $rows = $this->tab === 'not_found'
            ? NotFoundLog::query()->when($term !== '', fn ($q) => $q->where('path', 'like', '%'.$term.'%'))->orderByDesc('hits')->orderByDesc('last_seen_at')->paginate(50)
            : Redirect::query()->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('from_path', 'like', '%'.$term.'%')->orWhere('to_url', 'like', '%'.$term.'%')))->orderBy('from_path')->paginate(50);

        return view('livewire.cms.redirect-manager', ['rows' => $rows, 'notFoundCount' => NotFoundLog::count()])
            ->layout('components.layouts.crm')->title(__('cms.redirects.title'));
    }
}
