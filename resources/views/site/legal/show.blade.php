@extends('site.layout')

@section('content')
<header class="page-hero">
    <div class="wrap">
        <h1>{{ $document->getTranslation('title', app()->getLocale()) }}</h1>
        <p class="small">Versiunea {{ $document->version }}@if ($document->effective_at), în vigoare de la {{ $document->effective_at->translatedFormat('j F Y') }}@endif</p>
    </div>
</header>
<section class="section section--tight">
    <div class="wrap--narrow">
        @if ($document->type === 'cookies')
            <p class="mt-4"><button type="button" class="btn btn--ghost" data-consent="preferences">{{ __('site.footer.cookie_settings') }}</button></p>
        @endif
        <div class="prose mt-6">{!! $body !!}</div>
    </div>
</section>
@endsection
