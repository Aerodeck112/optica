@extends('site.layout')

@section('content')
<header class="page-hero">
    <div class="wrap">
        <h1 @if ($untranslated ?? false) lang="ro" @endif>{{ $document->getTranslation('title', app()->getLocale()) }}</h1>
        <p class="small">{{ __('site.legal_doc.version', ['version' => $document->version]) }}@if ($document->effective_at){{ __('site.legal_doc.effective', ['date' => $document->effective_at->translatedFormat('j F Y')]) }}@endif</p>
        @if ($untranslated ?? false)<p class="small mt-4" lang="{{ app()->getLocale() }}">{{ __('site.legal_doc.untranslated') }}</p>@endif
    </div>
</header>
<section class="section section--tight">
    <div class="wrap--narrow">
        @if ($document->type === 'cookies')
            <p class="mt-4"><button type="button" class="btn btn--ghost" data-consent="preferences">{{ __('site.footer.cookie_settings') }}</button></p>
        @endif
        <div class="prose mt-6" @if ($untranslated ?? false) lang="ro" @endif>{!! $body !!}</div>
    </div>
</section>
@endsection
