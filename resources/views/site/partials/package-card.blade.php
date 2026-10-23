@php
    $locale = app()->getLocale();
    $included = (array) ($package->getTranslation('included', $locale, false) ?: []);
    $instant = $package->supportsInstantBooking();
    $bookUrl = lroute('booking.show').'?pachet='.$package->getTranslation('slug', $locale);
@endphp
<article class="package @if ($package->is_featured) package--featured @endif">
    <div>
        <h3>{{ $package->name }}</h3>
        @if ($package->summary)<p class="muted mt-4">{{ $package->summary }}</p>@endif
    </div>
    <p class="package__price">
        @if ($package->hasPrice())
            @if ($package->pricing_model === 'per_person')
                {{ money($package->price_minor) }} <small>{{ __('site.packages.per_person') }}</small>
            @else
                {{ money($package->price_minor) }}
            @endif
        @else
            <small>{{ __('site.packages.price_on_request') }}</small>
        @endif
    </p>
    <ul class="package__facts" role="list">
        @if ($package->min_participants && $package->max_participants)
            <li>{{ __('site.packages.participants', ['min' => $package->min_participants, 'max' => $package->max_participants]) }}</li>
        @elseif ($package->max_participants)
            <li>{{ __('site.packages.participants_max', ['max' => $package->max_participants]) }}</li>
        @endif
        @if ($package->slot_minutes)<li>{{ __('site.packages.slot', ['minutes' => $package->slot_minutes]) }}</li>@endif
        @if ($package->play_minutes)<li>{{ __('site.packages.play', ['minutes' => $package->play_minutes]) }}</li>@endif
        @if ($package->hasPrice() && $package->deposit_type !== 'none')
            <li>{{ __('site.packages.deposit', ['amount' => $package->deposit_type === 'percent' ? ($package->deposit_value / 100).'%' : money((int) $package->deposit_value)]) }}</li>
        @endif
    </ul>
    @if ($included)
        <div>
            <p class="small"><strong>{{ __('site.packages.included') }}</strong></p>
            <ul class="package__included mt-4" role="list">
                @foreach ($included as $line)
                    <li><x-aw-icon name="check"/><span>{{ is_array($line) ? ($line['text'] ?? '') : $line }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif
    <a class="btn {{ $package->is_featured ? 'btn--primary' : 'btn--ghost' }}" href="{{ $bookUrl }}">{{ $instant ? __('site.packages.choose', ['package' => $package->name]) : __('site.packages.request') }}</a>
</article>
