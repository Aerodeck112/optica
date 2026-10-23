<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Page;
use App\Support\Locales;
use Illuminate\Http\Request;

/**
 * Previzualizarea ciornei unei pagini, doar pentru personalul autentificat, prin URL semnat cu expirare.
 * Afișată în panou într-un iframe cu lățime desktop sau mobil.
 */
class PreviewController extends Controller
{
    public function __invoke(Request $request, string $type, int $id)
    {
        abort_unless($request->user()?->can('cms.manage'), 403);
        abort_unless($type === 'page', 404);

        $locale = Locales::isSupported($request->query('locale')) ? $request->query('locale') : Locales::DEFAULT;
        app()->setLocale($locale);

        $page = Page::query()->findOrFail($id);
        $data = app(PageController::class)->viewData($page, $locale, true);

        return response()->view('site.pages.blocks', $data)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
