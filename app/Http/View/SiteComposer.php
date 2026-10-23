<?php

namespace App\Http\View;

use App\Modules\Booking\Services\ScheduleService;
use App\Modules\Cms\Models\Banner;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Services\LinkResolver;
use App\Modules\Core\Models\Integration;
use App\Modules\Core\Settings\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SiteComposer
{
    private static ?array $shared = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly LinkResolver $links,
        private readonly ScheduleService $schedule,
    ) {}

    public function compose(View $view): void
    {
        $view->with(self::$shared ??= $this->data());
    }

    public static function flush(): void
    {
        self::$shared = null;
        foreach (['ro', 'en', 'hu'] as $locale) {
            Cache::forget('aw.menus.'.$locale);
        }
    }

    private function data(): array
    {
        $locale = app()->getLocale();
        $f = fn (string $key) => $this->settings->filled($key);

        $menus = Cache::remember('aw.menus.'.$locale, 600, function () use ($locale) {
            $out = [];
            foreach (Menu::query()->with(['items' => fn ($q) => $q->where('is_visible', true)->orderBy('sort')])->get() as $menu) {
                $out[$menu->location] = $menu->items
                    ->whereNull('parent_id')
                    ->map(fn (MenuItem $item) => $this->menuEntry($item, $locale))
                    ->filter(fn ($entry) => $entry['url'])
                    ->values()
                    ->all();
            }

            return $out;
        });

        $legal = [];
        foreach (['terms', 'privacy', 'cookies', 'booking_policy', 'voucher_terms', 'arena_rules'] as $type) {
            $document = LegalDocument::current($type);
            if ($document) {
                $legal[$type] = [
                    'label' => $document->getTranslation('title', $locale, true),
                    'url' => lroute('legal.show', ['slug' => $document->getTranslation('slug', $locale, true)], $locale),
                ];
            }
        }

        $analytics = array_filter([
            'gtm' => $f('analytics.gtm_id'),
            'ga4' => $f('analytics.ga4_id'),
            'ads' => $f('analytics.ads_id'),
            'meta' => $f('analytics.meta_pixel_id'),
        ]);

        $aiIntegration = Integration::query()->where('key', 'ai')->first();

        return [
            'menus' => $menus,
            'legalLinks' => $legal,
            'contact' => array_filter([
                'phone' => $f('contact.phone_main'),
                'phone_bookings' => $f('contact.phone_bookings'),
                'whatsapp' => $f('contact.whatsapp'),
                'email' => $f('contact.email_general'),
                'email_bookings' => $f('contact.email_bookings'),
                'address' => $f('venue.address_street'),
                'city' => $f('venue.address_city') ?? 'Cluj-Napoca',
                'maps_url' => $f('venue.maps_url'),
            ]),
            'company' => array_filter([
                'name' => $f('company.legal_name'),
                'tax_id' => $f('company.tax_id'),
                'reg_no' => $f('company.reg_no'),
                'office' => $f('company.registered_office'),
            ]),
            'social' => array_filter([
                'facebook' => $f('social.facebook'),
                'instagram' => $f('social.instagram'),
                'tiktok' => $f('social.tiktok'),
                'youtube' => $f('social.youtube'),
            ]),
            'hours' => $this->schedule->isConfigured() ? $this->schedule->weeklyForDisplay() : null,
            'banners' => Banner::query()->live()->where('placement', 'top')->orderBy('sort')->get()
                ->filter(fn (Banner $b) => in_array($locale, (array) $b->published_locales, true))
                ->values(),
            'analyticsConfig' => $analytics,
            'needsConsent' => $analytics !== [],
            'aiEnabled' => $this->settings->bool('ai.enabled') && $aiIntegration?->isUsable(),
            'bookingOnline' => $this->settings->bool('booking.online_enabled'),
        ];
    }

    private function menuEntry(MenuItem $item, string $locale): array
    {
        $url = match ($item->link_type) {
            'page' => $item->page_id ? $this->links->url('page:'.optional($item->page)->key, $locale) : null,
            'route' => $this->links->url('route:'.$item->route, $locale),
            default => LinkResolver::isSafe($item->url) ? $item->url : null,
        };

        return [
            'label' => $item->getTranslation('label', $locale, true),
            'url' => $url,
            'style' => $item->style,
            'new_tab' => $item->open_new_tab,
        ];
    }
}
