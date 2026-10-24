@php
    // Paginile de eroare nu depind de baza de date (funcționează și când aceasta este indisponibilă).
    $segment = request()->segment(1);
    $locale = in_array($segment, \App\Support\Locales::enabled(), true) ? $segment : app()->getLocale();
    app()->setLocale(\App\Support\Locales::isSupported($locale) ? $locale : \App\Support\Locales::DEFAULT);
    $home = url(\App\Support\Locales::prefix(app()->getLocale()) ?: '/');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('site.errors.'.$code.'_title') }} — Another World Cluj</title>
<style>
  @font-face { font-family: "Onest AW"; src: url("/fonts/aw/onest-aw.woff2") format("woff2"); font-weight: 300 800; font-display: swap; }
  :root { color-scheme: dark; }
  * { box-sizing: border-box; margin: 0; }
  body { min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem; font-family: "Onest AW", system-ui, -apple-system, "Segoe UI", sans-serif;
    color: #eef0ff; background: radial-gradient(90% 70% at 80% 0%, #1c2668 0%, transparent 60%), radial-gradient(70% 60% at 0% 100%, #3a0f3f 0%, transparent 60%), #05040f; }
  main { max-width: 34rem; text-align: center; }
  img { height: 2.5rem; width: auto; margin: 0 auto 2.5rem; display: block; }
  .code { font-size: clamp(4rem, 18vw, 7.5rem); font-weight: 800; line-height: 1; letter-spacing: -.04em;
    background: linear-gradient(100deg, #3ee6ff, #7b4dff 55%, #f23db3); -webkit-background-clip: text; background-clip: text; color: transparent; }
  h1 { font-size: 1.5rem; margin: 1rem 0 .75rem; font-weight: 700; }
  p { color: #a9b0d6; line-height: 1.6; }
  a.btn { display: inline-block; margin-top: 2rem; padding: .85rem 1.5rem; border-radius: 999px; background: #e61c4a; color: #fff; text-decoration: none; font-weight: 600; }
  a.btn:hover { background: #ff3864; }
  a.btn:focus-visible { outline: 3px solid #3ee6ff; outline-offset: 3px; }
</style>
</head>
<body>
<main>
    <img src="/media/brand/aw-logo-horizontal.svg" alt="Another World Cluj">
    <div class="code" aria-hidden="true">{{ $code }}</div>
    <h1>{{ __('site.errors.'.$code.'_title') }}</h1>
    <p>{{ __('site.errors.'.$code.'_text') }}</p>
    @unless ($code === '503')<a class="btn" href="{{ $home }}">{{ __('site.errors.home') }}</a>@endunless
</main>
</body>
</html>
