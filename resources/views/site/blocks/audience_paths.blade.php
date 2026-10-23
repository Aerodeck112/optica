<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        @if ($b['heading'] || $b['intro'])
            <div class="section-head">
                @if ($b['heading'])<h2>{{ $b['heading'] }}</h2>@endif
                @if ($b['intro'])<p>{{ $b['intro'] }}</p>@endif
            </div>
        @endif
        <div class="paths">
            @foreach ($b['items'] as $item)
                @php $tag = $item['url'] ? 'a' : 'div'; @endphp
                <{{ $tag }} class="path" @if ($item['url']) href="{{ $item['url'] }}" @endif>
                    @if ($item['image'])<x-picture :media="$item['image']" :sizes="$loop->first ? '(max-width: 42rem) 100vw, 58vw' : '(max-width: 42rem) 100vw, 42vw'" alt=""/>@endif
                    <h3>{{ $item['title'] }}</h3>
                    @if ($item['text'])<p>{{ $item['text'] }}</p>@endif
                    @if ($item['url'])<span class="path__more">{{ $item['link_label'] }} <x-aw-icon name="arrow"/></span>@endif
                </{{ $tag }}>
            @endforeach
        </div>
        @if ($b['note'])<p class="source-note">{{ $b['note'] }}</p>@endif
    </div>
</section>
