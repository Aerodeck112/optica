<a class="post-card" href="{{ lroute('news.show', ['slug' => $post->getTranslation('slug', app()->getLocale())]) }}">
    @if ($post->cover)<x-picture :media="$post->cover" sizes="(max-width: 42rem) 100vw, 33vw" alt=""/>@endif
    @if ($post->published_at)<time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->translatedFormat('j F Y') }}</time>@endif
    <h3>{{ $post->title }}</h3>
    @if ($post->excerpt)<p>{{ $post->excerpt }}</p>@endif
</a>
