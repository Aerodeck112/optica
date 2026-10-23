@extends('site.layout')

@section('content')
    @if ($isPreview ?? false)
        <div class="staging-ribbon">Previzualizare ciornă — pagina nu este publicată în această formă</div>
    @endif
    @if ($showPageHero)
        <header class="page-hero">
            <div class="wrap">
                @if (count($seo->breadcrumbs) > 1)
                    <nav aria-label="Breadcrumb">
                        <ol class="breadcrumbs">
                            @foreach ($seo->breadcrumbs as $crumb)
                                <li>@if (! $loop->last)<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@else<span aria-current="page">{{ $crumb['name'] }}</span>@endif</li>
                            @endforeach
                        </ol>
                    </nav>
                @endif
                <h1>{{ $page->getTranslation('title', app()->getLocale()) }}</h1>
                @if ($seo->description)<p>{{ $seo->description }}</p>@endif
            </div>
        </header>
    @endif

    @foreach ($blocks as $block)
        @include($block['view'], ['b' => $block['data'], 'blockIndex' => $loop->index])
    @endforeach
@endsection
