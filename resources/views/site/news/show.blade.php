@extends('site.layout')

@section('content')
<article>
    <header class="page-hero">
        <div class="wrap">
            <nav aria-label="Breadcrumb"><ol class="breadcrumbs"><li><a href="{{ url('/') }}">{{ __('site.brand') }}</a></li><li><a href="{{ lroute('news.index') }}">{{ __('site.news.title') }}</a></li></ol></nav>
            <h1>{{ $post->title }}</h1>
            @if ($post->published_at)<p class="small"><time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->translatedFormat('j F Y') }}</time></p>@endif
        </div>
    </header>
    @if ($post->cover)
        <div class="wrap mt-7"><x-picture :media="$post->cover" sizes="(max-width: 80rem) 100vw, 80rem" :eager="true" style="border-radius:var(--radius-l);aspect-ratio:21/9"/></div>
    @endif
    <div class="section section--tight"><div class="wrap--narrow prose">{!! $body !!}</div></div>
</article>
@endsection
