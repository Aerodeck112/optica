@php use App\Domain\Cms\SectionView as S; use App\Support\Ui\SiteUrl; $locations = $catalog->locations(); $first_slots = $catalog->firstAvailableByLocation(); $tz = config('optica.clinic_timezone'); $today = now($tz)->dayOfWeekIso; @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <div class="max-w-2xl">
            @if ($first)<h1 class="text-3xl font-light text-heading sm:text-4xl">{{ S::text($section, 'heading') }}</h1>@else<h2 class="text-2xl font-light text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>@endif
            <div class="mt-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
        </div>
        <ul class="mt-10 grid gap-x-10 md:grid-cols-2">
            @foreach ($locations as $l)
                @php($hoursToday = $l->opening_hours[$today][0] ?? null)
                <li class="border-b border-line py-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-lg font-medium text-heading"><a href="{{ SiteUrl::path('puncte-lucru/'.$l->code) }}" class="hover:underline">{{ $l->publicValue('address') ?? $l->address }}</a></h3>
                        <span class="text-sm text-muted">{{ $l->publicValue('city') ?? $l->city }}</span>
                    </div>
                    <p class="mt-1 text-sm">
                        @if ($hoursToday){{ __('site.locations.open_today', ['from' => $hoursToday[0], 'to' => $hoursToday[1]]) }}@else{{ __('site.locations.closed_today') }}@endif
                        @if ($l->phone) · <a href="tel:{{ preg_replace('/[^0-9+]/', '', $l->phone) }}" class="font-semibold text-ink hover:underline">{{ $l->phone }}</a>@endif
                    </p>
                    @if ($l->online_booking_enabled)
                        <p class="mt-2 text-sm">
                            @if (isset($first_slots[$l->id]))
                                {{ __('site.locations.first_available') }} <span class="font-semibold text-heading">{{ ucfirst($first_slots[$l->id]->setTimezone($tz)->locale(app()->getLocale())->calendar(null, ['sameDay' => '['.__('site.today').'] HH:mm', 'nextDay' => '['.__('site.tomorrow').'] HH:mm', 'nextWeek' => 'dddd HH:mm', 'sameElse' => 'D MMM, HH:mm'])) }}</span> ·
                            @endif
                            <a href="{{ SiteUrl::route('booking', ['locatie' => $l->code]) }}" class="btn-link">{{ __('site.book_here') }}</a>
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</section>
