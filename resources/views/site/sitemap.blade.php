{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as $u)
@foreach (['ro', 'hu'] as $l)
  <url>
    <loc>{{ $u[$l] }}</loc>
    <xhtml:link rel="alternate" hreflang="ro" href="{{ $u['ro'] }}"/>
    <xhtml:link rel="alternate" hreflang="hu" href="{{ $u['hu'] }}"/>
@if ($u['updated'])    <lastmod>{{ $u['updated']->toAtomString() }}</lastmod>
@endif
  </url>
@endforeach
@endforeach
</urlset>
