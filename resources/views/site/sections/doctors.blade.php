@php use App\Domain\Cms\SectionView as S; use App\Support\Ui\SiteUrl; $specialists = $catalog->specialists(); $locations = $catalog->locations(); @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}" x-data="{ loc: '', lang: '', type: '' }">
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <div class="max-w-3xl">
            <h2 class="text-2xl font-light text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>
            <div class="mt-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
        </div>
        @if ($specialists->isNotEmpty())
            <div class="mt-8 flex flex-wrap gap-4" role="search">
                <div>
                    <label for="f-loc" class="field-label">{{ __('site.filters.location') }}</label>
                    <select id="f-loc" x-model="loc" class="input"><option value="">{{ __('site.filters.all') }}</option>@foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->publicValue('name') }}</option>@endforeach</select>
                </div>
                <div>
                    <label for="f-type" class="field-label">{{ __('site.filters.specialty') }}</label>
                    <select id="f-type" x-model="type" class="input"><option value="">{{ __('site.filters.all') }}</option>@foreach (\App\Models\Specialist::TYPES as $t)<option value="{{ $t }}">{{ __('crm.specialists.types.'.$t) }}</option>@endforeach</select>
                </div>
                <div>
                    <label for="f-lang" class="field-label">{{ __('site.filters.language') }}</label>
                    <select id="f-lang" x-model="lang" class="input"><option value="">{{ __('site.filters.all') }}</option><option value="ro">Română</option><option value="hu">Magyar</option></select>
                </div>
            </div>
            <ul class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($specialists as $sp)
                    <li x-show="(!loc || {{ json_encode($sp->locations->pluck('id')->map(fn ($i) => (string) $i)->values()) }}.includes(loc)) && (!lang || {{ json_encode($sp->languages ?? []) }}.includes(lang)) && (!type || type === '{{ $sp->type }}')">
                        <a href="{{ SiteUrl::path('medici/'.$sp->code) }}" class="group block">
                            @if ($sp->photo_path)
                                <img src="{{ Storage::disk('public')->url($sp->photo_path) }}" alt="" loading="lazy" class="aspect-[5/4] w-full rounded-[50%] object-cover">
                            @else
                                <div class="flex aspect-[5/4] w-full items-center justify-center rounded-[50%] bg-mist text-4xl font-light text-ink" aria-hidden="true">{{ mb_substr(preg_replace('/^(Dr\.?\s*)/i', '', $sp->display_name), 0, 1) }}</div>
                            @endif
                            <p class="mt-4 text-lg font-medium text-heading group-hover:underline">{{ $sp->display_name }}</p>
                            <p class="text-sm text-body">{{ $sp->publicValue('title') ?: __('crm.specialists.types.'.$sp->type) }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
