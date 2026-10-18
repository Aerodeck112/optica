<?php

namespace App\Support\Ui;

/**
 * Public URLs: Romanian without prefix, Hungarian under /hu (D-011). Route names are defined twice:
 * "site.x" and "site.hu.x".
 */
class SiteUrl
{
    public static function route(string $name, array $parameters = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return route($locale === 'hu' ? 'site.hu.'.$name : 'site.'.$name, $parameters);
    }

    /** A public path such as "servicii" in the given language; canonical URLs have no trailing slash (D-012). */
    public static function path(string $slug = '', ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $path = trim(($locale === 'hu' ? 'hu/' : '').trim($slug, '/'), '/');

        return url($path === '' ? '/' : $path);
    }

    /** The same page in the other language (keeps the path and query). */
    public static function switchTo(string $locale): string
    {
        $request = request();
        $path = ltrim($request->path(), '/');
        $path = preg_replace('#^hu(/|$)#', '', $path);
        $path = $locale === 'hu' ? 'hu/'.$path : $path;
        $query = $request->getQueryString();

        return url($path === '' ? '/' : $path).($query ? '?'.$query : '');
    }
}
