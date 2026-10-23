<section class="section section--tight" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="{{ $b['width'] === 'wide' ? 'wrap' : 'wrap--narrow' }}">
        @if ($b['heading'])<h2>{{ $b['heading'] }}</h2>@endif
        <div class="prose @if ($b['heading']) mt-5 @endif">{!! $b['body'] !!}</div>
    </div>
</section>
