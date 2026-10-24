<?php

namespace App\Filament\Widgets;

use App\Modules\Booking\Services\ScheduleService;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Core\Models\Integration;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Integrations\IntegrationCatalog;
use Filament\Widgets\Widget;

/** Ce mai lipsește înainte de lansare: date de completat, integrări neconfigurate, documente nevalidate. */
class LaunchReadiness extends Widget
{
    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.launch-readiness';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('settings.manage') || $user?->hasRole('manager'));
    }

    protected function getViewData(): array
    {
        $missing = app(SettingsRepository::class)->missingForLaunch();
        $items = [];
        $items[] = ['ok' => $missing === [], 'label' => $missing ? count($missing).' date firmă/locație de completat' : 'Datele firmei și ale locației sunt completate', 'url' => route('filament.admin.pages.settings')];
        $items[] = ['ok' => app(ScheduleService::class)->isConfigured(), 'label' => 'Programul de funcționare', 'url' => route('filament.admin.pages.opening-hours')];
        $instant = Package::query()->where('is_active', true)->get()->filter->supportsInstantBooking()->count();
        $items[] = ['ok' => $instant > 0, 'label' => $instant ? "{$instant} pachete rezervabile online" : 'Niciun pachet cu preț, durată și capacitate (rezervările rămân cereri)', 'url' => route('filament.admin.resources.packages.index')];
        $games = Game::query()->where('cluj_status', 'available')->count();
        $items[] = ['ok' => $games > 0, 'label' => $games ? "{$games} jocuri confirmate pentru Cluj" : 'Niciun joc confirmat pentru Cluj', 'url' => route('filament.admin.resources.games.index')];
        $flags = LegalDocument::query()->where('status', 'published')->get()->filter->hasOpenReviewFlags()->count();
        $items[] = ['ok' => $flags === 0, 'label' => $flags ? "{$flags} documente juridice cu puncte nevalidate" : 'Documentele juridice sunt validate', 'url' => route('filament.admin.resources.legal-documents.index')];
        foreach (Integration::query()->orderBy('id')->get() as $integration) {
            $items[] = [
                'ok' => in_array($integration->status, ['configured', 'production_verified'], true),
                'label' => (IntegrationCatalog::get($integration->key)['label'] ?? $integration->key).': '.(IntegrationCatalog::STATUSES[$integration->status] ?? $integration->status),
                'url' => auth()->user()?->can('integrations.manage') ? route('filament.admin.resources.integrations.edit', $integration) : null,
            ];
        }

        return ['items' => $items];
    }
}
