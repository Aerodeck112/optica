@php
    use App\Support\Ui\SiteUrl;
    $doctors = $catalog->specialists()->filter(fn ($s) => $s->locations->contains('id', $location->id));
    $mapQuery = urlencode(($location->address).', '.$location->city.', Romania');
@endphp
<x-layouts.site :title="$meta['title']" :description="$meta['description']" :canonical="$meta['canonical']" :alternates="$meta['alternates']">
    <article class="bg-paper">
        <div class="mx-auto grid max-w-7xl gap-12 px-4 py-14 sm:px-6 lg:grid-cols-2">
            <div>
                <p class="text-sm"><a href="{{ SiteUrl::path('puncte-lucru') }}" class="btn-link">{{ __('site.nav.locations') }}</a></p>
                <h1 class="mt-3 text-3xl font-light text-heading sm:text-4xl">{{ $location->publicValue('address') ?? $location->address }}</h1>
                <p class="mt-1 text-lg">{{ $location->publicValue('city') ?? $location->city }}</p>
                <div class="mt-5 space-y-4 leading-relaxed">{{ \App\Domain\Cms\SafeText::render($location->publicValue('description')) }}</div>
                <dl class="mt-6 grid grid-cols-[8rem_1fr] gap-y-2">
                    @if ($location->phone)<dt class="text-muted">{{ __('crm.fields.phone') }}</dt><dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $location->phone) }}" class="font-semibold text-ink">{{ $location->phone }}</a></dd>@endif
                    @if ($location->email)<dt class="text-muted">Email</dt><dd><a href="mailto:{{ $location->email }}" class="text-ink">{{ $location->email }}</a></dd>@endif
                </dl>
                <h2 class="mt-8 text-xl font-semibold">{{ __('site.locations.hours') }}</h2>
                <table class="mt-3 text-sm">
                    @foreach (range(1, 7) as $d)
                        @php($h = $location->opening_hours[$d][0] ?? null)
                        <tr><th scope="row" class="py-1 pr-6 text-left font-normal text-muted">{{ __('crm.weekdays.'.$d) }}</th><td>{{ $h ? $h[0].'–'.$h[1] : __('crm.locations.closed') }}</td></tr>
                    @endforeach
                </table>
                <p class="mt-2 text-xs text-muted">{{ __('site.locations.hours_note') }}</p>
                @if ($location->online_booking_enabled)
                    <a href="{{ SiteUrl::route('booking', ['locatie' => $location->code]) }}" class="btn mt-8 bg-lens px-6 py-3 text-ink hover:bg-lens-600">{{ __('site.book_here') }}</a>
                @endif
            </div>
            <div>
                @if ($location->latitude && $location->longitude)
                    <iframe title="{{ __('site.locations.map') }}" class="aspect-[4/3] w-full rounded-2xl ring-1 ring-line" loading="lazy" referrerpolicy="no-referrer"
                            src="https://www.openstreetmap.org/export/embed.html?bbox={{ $location->longitude - 0.006 }},{{ $location->latitude - 0.004 }},{{ $location->longitude + 0.006 }},{{ $location->latitude + 0.004 }}&amp;layer=mapnik&amp;marker={{ $location->latitude }},{{ $location->longitude }}"></iframe>
                @endif
                <a href="https://www.google.com/maps/search/?api=1&query={{ $mapQuery }}" target="_blank" rel="noopener" class="btn-secondary mt-4">{{ __('site.locations.open_map') }}</a>
                @if ($doctors->isNotEmpty())
                    <h2 class="mt-10 text-xl font-semibold">{{ __('site.locations.doctors_here') }}</h2>
                    <ul class="mt-3 space-y-1">@foreach ($doctors as $d)<li><a class="btn-link" href="{{ SiteUrl::path('medici/'.$d->code) }}">{{ $d->display_name }}</a></li>@endforeach</ul>
                @endif
            </div>
        </div>
    </article>
</x-layouts.site>
