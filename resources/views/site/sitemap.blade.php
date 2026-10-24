<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
@if ($entry['lastmod'])        <lastmod>{{ $entry['lastmod'] }}</lastmod>
@endif
@foreach ($entry['alternates'] as $locale => $url)
        <xhtml:link rel="alternate" hreflang="{{ \App\Support\Locales::SUPPORTED[$locale]['hreflang'] }}" href="{{ $url }}"/>
@endforeach
    </url>
@endforeach
</urlset>
