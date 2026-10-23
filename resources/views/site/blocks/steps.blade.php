<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        @if ($b['heading'])
            <div class="section-head">
                <h2>{{ $b['heading'] }}</h2>
                @if ($b['intro'])<p>{{ $b['intro'] }}</p>@endif
            </div>
        @endif
        <ol class="steps" role="list">
            @foreach ($b['steps'] as $step)
                <li class="step">
                    <h3>{{ $step['title'] }}</h3>
                    @if (! empty($step['text']))<p>{{ $step['text'] }}</p>@endif
                </li>
            @endforeach
        </ol>
    </div>
</section>
