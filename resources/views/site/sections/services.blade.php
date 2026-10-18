@php use App\Domain\Cms\SectionView as S; use App\Support\Ui\SiteUrl; $services = $catalog->services(); @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <div class="max-w-2xl">
            @if ($first)<h1 class="text-3xl font-light text-heading sm:text-4xl">{{ S::text($section, 'heading') }}</h1>@else<h2 class="text-2xl font-light text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>@endif
            <div class="mt-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
        </div>
        <ul class="mt-10 grid gap-x-10 gap-y-2 md:grid-cols-2">
            @foreach ($services as $s)
                <li class="border-b border-line">
                    <a href="{{ SiteUrl::path('servicii/'.$s->code) }}" class="group flex items-center gap-4 py-4">
                        @if ($s->image_id && ($m = S::media($s->image_id)))
                            <img src="{{ $m->url(480) }}" alt="" loading="lazy" class="h-14 w-16 shrink-0 rounded-[50%] object-cover">
                        @endif
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
