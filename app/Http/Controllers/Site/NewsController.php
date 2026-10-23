<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Post;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Cms\Seo\StructuredData;
use App\Modules\Cms\Services\SafeMarkdown;

class NewsController extends Controller
{
    public function index()
    {
        $locale = app()->getLocale();
        $posts = Post::query()->published()->publishedIn($locale)->with('cover')->latest('published_at')->paginate(12);

        $seo = Seo::make(__('site.news.title'));
        $seo->canonical = lroute('news.index');
        $seo->noindex($posts->isEmpty());

        return view('site.news.index', ['posts' => $posts, 'seo' => $seo]);
    }

    public function show(string $slug, StructuredData $structured)
    {
        $locale = app()->getLocale();
        $post = Post::query()->published()->where("slug->{$locale}", $slug)->with('cover')->first();
        abort_unless($post && $post->isPublishedIn($locale), 404);

        $url = lroute('news.show', ['slug' => $slug]);
        $seo = Seo::make($post->getTranslation('seo_title', $locale, false) ?: $post->title, $post->getTranslation('seo_description', $locale, false) ?: $post->excerpt);
        $seo->canonical = $url;
        $seo->type = 'article';
        if ($post->cover) {
            $seo->image($post->cover->urlForWidth(1200), $post->cover->altText($locale));
        }
        $seo->addJsonLd($structured->article($post->title, $url, $post->cover?->urlForWidth(1200), $post->published_at?->toIso8601String(), $post->updated_at?->toIso8601String()));
        $seo->breadcrumbs([
            ['name' => __('site.brand'), 'url' => url('/')],
            ['name' => __('site.news.title'), 'url' => lroute('news.index')],
            ['name' => $post->title, 'url' => $url],
        ]);

        return view('site.news.show', ['post' => $post, 'body' => SafeMarkdown::render($post->body['markdown'] ?? (is_string($post->body) ? $post->body : '')), 'seo' => $seo]);
    }
}
