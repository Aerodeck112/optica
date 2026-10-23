<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        @if ($b['heading'] || $b['intro'])
            <div class="section-head">
                @if ($b['heading'])<h2>{{ $b['heading'] }}</h2>@endif
                @if ($b['intro'])<p>{{ $b['intro'] }}</p>@endif
            </div>
        @endif
        @if ($b['packages']->isEmpty())
            <div class="notice">
                <x-aw-icon name="info"/>
                <div class="stack">
                    <p>{{ __('site.packages.empty') }}</p>
                    @if ($b['empty_cta'])<p><a class="btn btn--primary" href="{{ $b['empty_cta']['url'] }}">{{ $b['empty_cta']['label'] }}</a></p>@endif
                </div>
            </div>
        @else
            <div class="packages">
                @foreach ($b['packages'] as $package)
                    @include('site.partials.package-card', ['package' => $package])
                @endforeach
            </div>
        @endif
    </div>
</section>
