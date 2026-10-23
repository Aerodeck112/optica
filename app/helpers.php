<?php

use App\Modules\Core\Settings\SettingsRepository;
use App\Support\Locales;
use App\Support\Money;

if (! function_exists('lroute')) {
    /** URL pentru o rută publică în limba dată (implicit limba curentă). */
    function lroute(string $name, array $parameters = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        if (! in_array($locale, Locales::enabled(), true)) {
            $locale = Locales::DEFAULT;
        }

        return route($locale.'.'.$name, $parameters);
    }
}

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsRepository::class)->get($key, $default);
    }
}

if (! function_exists('filled_setting')) {
    /** Doar valorile completate explicit — pentru afișare publică (fără valori implicite sau fictive). */
    function filled_setting(string $key): ?string
    {
        return app(SettingsRepository::class)->filled($key);
    }
}

if (! function_exists('money')) {
    function money(?int $minor, string $currency = 'RON'): string
    {
        return Money::format($minor, $currency);
    }
}

if (! function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        return app()->bound('csp-nonce') ? app('csp-nonce') : '';
    }
}

if (! function_exists('aw_json')) {
    /** JSON sigur pentru atribute HTML și blocuri <script type="application/json"> (caracterele <, >, &, ', " escapate). */
    function aw_json(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
