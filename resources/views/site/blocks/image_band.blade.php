<section class="band" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <x-picture :media="$b['image']" sizes="100vw" alt=""/>
    <div class="wrap">
        @if ($b['text'])<p class="band__text">{{ $b['text'] }}</p>@endif
        @if ($b['note'])<p class="source-note" style="padding-bottom: var(--space-5)">{{ $b['note'] }}</p>@endif
    </div>
</section>
