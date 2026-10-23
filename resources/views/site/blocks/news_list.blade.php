<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        <div class="rail-head">
            @if ($b['heading'])<h2>{{ $b['heading'] }}</h2>@endif
            <a class="btn btn--ghost btn--small" href="{{ lroute('news.index') }}">{{ __('site.learn_more') }}</a>
        </div>
        <div class="posts">
            @foreach ($b['posts'] as $post)
                @include('site.news.card', ['post' => $post])
            @endforeach
        </div>
    </div>
</section>
