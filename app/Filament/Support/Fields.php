<?php

namespace App\Filament\Support;

use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\LinkResolver;
use App\Support\Locales;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Câmpuri reutilizabile în panou: traduceri RO/EN/HU, sume în lei (stocate în bani), media, linkuri sigure.
 */
final class Fields
{
    /**
     * Tab-uri pe limbă. Româna este limba principală (câmpuri obligatorii); EN/HU sunt opționale
     * și se publică doar dacă sunt bifate în „Limbi publicate”.
     *
     * @param  Closure(string $locale, bool $isDefault): array  $fields
     */
    public static function localeTabs(Closure $fields, string $id = 'locale'): Tabs
    {
        $tabs = [];
        foreach (Locales::SUPPORTED as $code => $meta) {
            $isDefault = $code === Locales::DEFAULT;
            $tabs[] = Tab::make($meta['name'])
                ->key($id.'-'.$code)
                ->badge($isDefault ? 'principală' : null)
                ->schema($fields($code, $isDefault));
        }

        return Tabs::make('Traduceri')->tabs($tabs)->columnSpanFull()->persistTab()->id($id.'-tabs');
    }

    public static function publishedLocales(): CheckboxList
    {
        return CheckboxList::make('published_locales')
            ->label('Limbi publicate')
            ->options(collect(Locales::SUPPORTED)->map(fn ($m) => $m['name'])->all())
            ->default([Locales::DEFAULT])
            ->columns(3)
            ->helperText('Bifează o limbă doar după ce traducerea este completă. Limbile nebifate nu apar pe site și nici în hreflang.');
    }

    /** Sumă în lei, stocată în bani (int). Gol = „nu este configurat” (nu 0). */
    public static function money(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->suffix('lei')
            ->inputMode('decimal')
            ->rule('nullable')
            ->rule('regex:/^\d{1,7}([.,]\d{1,2})?$/')
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::toDecimalString((int) $state))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::parse($state));
    }

    public static function media(string $name, string $label, string $kind = 'image'): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Media::query()
                ->where('kind', $kind)
                ->where(fn ($q) => $q->where('original_name', 'like', "%{$search}%")->orWhere('static_key', 'like', "%{$search}%")->orWhere('alt', 'like', "%{$search}%"))
                ->limit(30)->get()->mapWithKeys(fn (Media $m) => [$m->id => self::mediaLabel($m)])->all())
            ->getOptionLabelUsing(fn ($value) => ($m = Media::query()->find($value)) ? self::mediaLabel($m) : null)
            ->options(fn () => Media::query()->where('kind', $kind)->latest('id')->limit(40)->get()->mapWithKeys(fn (Media $m) => [$m->id => self::mediaLabel($m)])->all())
            ->helperText('Fișierele cu drepturi „Necunoscut” nu se afișează pe site.');
    }

    public static function mediaLabel(Media $media): string
    {
        $name = $media->static_key ?: $media->original_name ?: $media->filename;
        $flag = $media->rights_status === 'unknown' ? ' ⚠ drepturi necunoscute' : '';

        return "#{$media->id} · {$name} · ".(Media::CATEGORIES[$media->category] ?? $media->category).$flag;
    }

    /** Link CMS: page:cheie, route:nume, #ancoră, /cale sau URL http(s)/mailto/tel. */
    public static function link(string $name, string $label = 'Link'): TextInput
    {
        $suggestions = collect(LinkResolver::ROUTES)->keys()->map(fn ($r) => "route:{$r}")
            ->merge(Page::query()->pluck('key')->map(fn ($k) => "page:{$k}"))
            ->all();

        return TextInput::make($name)
            ->label($label)
            ->datalist($suggestions)
            ->maxLength(500)
            ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                if (! LinkResolver::isSafe($value)) {
                    $fail('Link invalid. Folosește page:cheie, route:nume, #ancoră, /cale sau o adresă https://.');
                }
            })
            ->helperText('Ex.: page:contact, route:booking, #cerere sau https://…');
    }

    /** Text simplu cu limită — fără HTML. */
    public static function text(string $name, string $label, int $max = 255): TextInput
    {
        return TextInput::make($name)->label($label)->maxLength($max);
    }
}
