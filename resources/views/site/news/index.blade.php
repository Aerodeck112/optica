@extends('site.layout')

@section('content')
<header class="page-hero"><div class="wrap"><h1>{{ __('site.news.title') }}</h1></div></header>
<section class="section section--tight">
    <div class="wrap">
        @if ($posts->isEmpty())
            <p class="muted">{{ __('site.news.empty') }}</p>
        @else
            <div class="posts">@foreach ($posts as $post)@include('site.news.card', ['post' => $post])@endforeach</div>
            <div class="mt-7">{{ $posts->links() }}</div>
        @endif
    </div>
</section>
@endsection
