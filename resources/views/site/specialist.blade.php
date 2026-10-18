@php use App\Support\Ui\SiteUrl; $locs = $specialist->locations->filter(fn ($l) => $l->is_public && $l->published_at); @endphp
<x-layouts.site :title="$meta['title']" :description="$meta['description']" :canonical="$meta['canonical']" :alternates="$meta['alternates']">
    <article class="bg-paper">
        <div class="mx-auto grid max-w-6xl gap-12 px-4 py-14 sm:px-6 md:grid-cols-[18rem_1fr]">
            <div>
                @if ($specialist->photo_path)
                    <img src="{{ Storage::disk('public')->url($specialist->photo_path) }}" alt="{{ $specialist->display_name }}" class="aspect-[4/5] w-full rounded-[50%] object-cover">
                @endif
            </div>
            <div>
                <p class="text-sm"><a href="{{ SiteUrl::path('medici') }}" class="btn-link">{{ __('site.nav.doctors') }}</a></p>
                <h1 class="mt-3 text-3xl font-light text-heading sm:text-4xl">{{ $specialist->display_name }}</h1>
                <p class="mt-2 text-lg text-body">{{ $specialist->publicValue('title') ?: __('crm.specialists.types.'.$specialist->type) }}</p>
                @if ($specialist->languages)<p class="mt-2 text-sm text-muted">{{ __('site.booking.speaks') }}: {{ collect($specialist->languages)->map(fn ($l) => __('crm.spoken.'.$l))->join(', ') }}</p>@endif
                <div class="mt-6 space-y-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render($specialist->publicValue('bio')) }}</div>
                @if ($specialist->publicValue('specialties'))
                    <h2 class="mt-8 text-xl font-semibold">{{ __('crm.fields.specialties') }}</h2>
                    <ul class="mt-3 list-disc space-y-1 pl-5">@foreach (preg_split('/\R/', $specialist->publicValue('specialties')) as $line)@if (trim($line))<li>{{ trim($line, "- \t") }}</li>@endif @endforeach</ul>
                @endif
                @if ($locs->isNotEmpty())
                    <h2 class="mt-8 text-xl font-semibold">{{ __('site.specialist.where') }}</h2>
                    <ul class="mt-3 space-y-1">@foreach ($locs as $l)<li><a class="btn-link" href="{{ SiteUrl::path('puncte-lucru/'.$l->code) }}">{{ $l->publicValue('name') }}</a></li>@endforeach</ul>
                @endif
                <a href="{{ SiteUrl::route('booking') }}" class="btn mt-8 bg-lens px-6 py-3 text-ink hover:bg-lens-600">{{ __('site.book_online') }}</a>
            </div>
        </div>
    </article>
</x-layouts.site>
