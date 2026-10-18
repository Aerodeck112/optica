@php use App\Domain\Cms\SectionView as S; use App\Support\Ui\SiteUrl; $services = $catalog->services(); @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:py-20">
        <div class="grid items-center gap-10 lg:grid-cols-[1fr_1.1fr]">
            <div class="max-w-xl">
                @if ($first)<h1 class="font-display text-3xl font-semibold text-heading sm:text-4xl">{{ S::text($section, 'heading') }}</h1>@else<h2 class="font-display text-2xl font-semibold text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>@endif
                <div class="mt-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
            </div>
            <x-site.eye-diagram class="mx-auto w-full max-w-lg" />
        </div>
        <ul class="mt-12 grid gap-x-10 md:grid-cols-2">
            @foreach ($services as $s)
                <li class="border-b border-line">
                    <a href="{{ SiteUrl::path('servicii/'.$s->code) }}" class="group flex items-center gap-4 py-4">
                        {{-- Each examination with the instrument or structure it is about, inside a lens outline. --}}
                        <span class="flex h-12 w-14 shrink-0 items-center justify-center rounded-[50%] bg-mist text-ink ring-1 ring-sky/40 transition-colors group-hover:bg-lens">
                            <x-site.optic-icon :name="$s->code" class="h-6 w-6" />
                        </span>
                        <span class="flex-1">
                            <span class="block font-medium text-heading group-hover:text-ink group-hover:underline">{{ $s->publicValue('name') }}</span>
                            @if ($first && $s->publicValue('description'))<span class="mt-1 block text-sm text-body">{{ \Illuminate\Support\Str::limit($s->publicValue('description'), 140) }}</span>@endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>
