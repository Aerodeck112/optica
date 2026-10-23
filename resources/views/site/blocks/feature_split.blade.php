<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap split split--tone-{{ $b['tone'] }} @if ($b['image_side'] === 'right') split--reverse @endif">
        @if ($b['image'])
            <div class="split__media @if ($b['image_shape'] === 'wide') split__media--wide @endif">
                <div class="split__accent" aria-hidden="true"></div>
                <x-picture :media="$b['image']" sizes="(max-width: 64rem) 100vw, 46vw"/>
                @if ($b['media_note'])<p class="source-note">{{ $b['media_note'] }}</p>@endif
            </div>
        @endif
        <div class="split__content">
            <h2>{{ $b['heading'] }}</h2>
            @if ($b['body'])<p>{{ $b['body'] }}</p>@endif
            @if ($b['bullets'])
                <ul class="split__list" role="list">
                    @foreach ($b['bullets'] as $bullet)
                        <li><x-aw-icon name="check"/><span>{{ $bullet }}</span></li>
                    @endforeach
                </ul>
            @endif
            @if ($b['primary'] || $b['secondary'])
                <div class="split__actions">
                    @if ($b['primary'])<a class="btn btn--primary" href="{{ $b['primary']['url'] }}">{{ $b['primary']['label'] }}</a>@endif
                    @if ($b['secondary'])<a class="btn btn--ghost" href="{{ $b['secondary']['url'] }}">{{ $b['secondary']['label'] }}</a>@endif
                </div>
            @endif
        </div>
    </div>
</section>
