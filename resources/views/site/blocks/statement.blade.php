<section class="section statement" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        <p class="statement__text">{{ $b['text'] }}</p>
        @if ($b['body'])<div class="statement__body prose">{!! $b['body'] !!}</div>@endif
    </div>
</section>
