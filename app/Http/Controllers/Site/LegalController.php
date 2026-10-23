<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Cms\Services\SafeMarkdown;

class LegalController extends Controller
{
    public function show(string $slug)
    {
        $locale = app()->getLocale();
        $document = LegalDocument::query()
            ->where("slug->{$locale}", $slug)
            ->where('status', 'published')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        // doar versiunea curentă a tipului este publică
        abort_unless($document && LegalDocument::current($document->type)?->is($document), 404);

        $seo = Seo::make($document->getTranslation('title', $locale), SafeMarkdown::plain($document->getTranslation('body', $locale), 155));
        $seo->canonical = lroute('legal.show', ['slug' => $slug]);

        return view('site.legal.show', [
            'document' => $document,
            'body' => SafeMarkdown::render(self::fillPlaceholders($document->getTranslation('body', $locale))),
            'seo' => $seo,
        ]);
    }

    /** Completează {{ cheie.setare }} cu valorile din setări; câmpurile lipsă apar marcate „[de completat]”. */
    public static function fillPlaceholders(string $body): string
    {
        $settings = app(\App\Modules\Core\Settings\SettingsRepository::class);

        return preg_replace_callback('/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/', function ($m) use ($settings) {
            $value = $settings->get($m[1]);
            if ($value === null || $value === '') {
                $label = \App\Modules\Core\Settings\SettingsSchema::get($m[1])['label'] ?? $m[1];

                return '[de completat: '.$label.']';
            }

            return is_array($value) ? implode(', ', $value) : (string) $value;
        }, $body);
    }
}
