<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        @if ($b['heading'])
            <div class="section-head">
                <h2>{{ $b['heading'] }}</h2>
                @if ($b['intro'])<p>{{ $b['intro'] }}</p>@endif
            </div>
        @endif
        <div class="mosaic">
            @foreach ($b['images'] as $image)
                <button type="button" class="mosaic__item" data-lightbox data-full="{{ $image->urlForWidth(1600, ['webp', 'jpg']) }}" data-alt="{{ $image->altText() }}" aria-label="{{ $image->altText() ?: __('site.games.gallery') }}">
                    <x-picture :media="$image" sizes="(max-width: 42rem) 100vw, 33vw"/>
                </button>
            @endforeach
        </div>
    </div>
</section>
