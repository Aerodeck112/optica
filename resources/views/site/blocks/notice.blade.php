<section class="section section--tight" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        <div class="notice @if ($b['tone'] === 'warning') notice--warning @endif" role="note">
            <x-aw-icon :name="$b['tone'] === 'warning' ? 'warning' : 'info'"/>
            <div class="stack">
                @if ($b['heading'])<h2 class="h4" style="font-size:var(--fs-md)">{{ $b['heading'] }}</h2>@endif
                <div class="prose">{!! $b['body'] !!}</div>
            </div>
        </div>
    </div>
</section>
