<?php

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\Page;
use App\Support\Locales;

/**
 * Linkurile din CMS se salvează ca „page:cheie”, „route:nume”, „#ancoră” sau URL http(s)/mailto/tel.
 * Orice altă schemă (ex: javascript:) este respinsă.
 */
class LinkResolver
{
    public const ROUTES = [
        'booking' => 'Rezervare',
        'games' => 'Jocuri VR',
        'news' => 'Noutăți',
        'account' => 'Cont client',
    ];

    private array $pageCache = [];

    public static function isSafe(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (preg_match('/^(page|route):[a-z0-9_.\-]+(\?[a-z0-9_=&\-]*)?$/i', $value) || str_starts_with($value, '#')) {
            return true;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return ! preg_match('/[\s<>"\']/', $value);
        }

        return (bool) preg_match('/^(https?:\/\/[^\s<>"\']+|mailto:[^\s<>"\']+|tel:\+?[0-9 ]+)$/i', $value);
    }

    public function url(?string $value, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        if (! $value || ! self::isSafe($value)) {
            return null;
        }

        if (str_starts_with($value, 'page:')) {
            [$key, $query] = array_pad(explode('?', substr($value, 5), 2), 2, null);
            $page = $this->pageCache[$key] ??= Page::query()->where('key', $key)->first();
            if (! $page || ! $page->isLive()) {
                return null;
            }
            $target = $page->isPublishedIn($locale) ? $locale : Locales::DEFAULT;

            return $page->url($target).($query ? '?'.$query : '');
        }

        if (str_starts_with($value, 'route:')) {
            [$name, $query] = array_pad(explode('?', substr($value, 6), 2), 2, null);
            $routeName = match ($name) {
                'booking' => 'booking.show',
                'games' => 'games.index',
                'news' => 'news.index',
                'account' => 'account.show',
                default => null,
            };
            if (! $routeName) {
                return null;
            }

            return lroute($routeName, [], $locale).($query ? '?'.$query : '');
        }

        return $value;
    }

    public static function isExternal(?string $url): bool
    {
        if (! $url || ! preg_match('/^https?:\/\//i', $url)) {
            return false;
        }

        return parse_url($url, PHP_URL_HOST) !== parse_url(config('app.url'), PHP_URL_HOST);
    }
}
