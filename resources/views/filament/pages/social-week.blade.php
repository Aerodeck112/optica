@php
    $monthNames = ['', 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
    $dayNames = ['', 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă', 'Duminică'];
    $monday = $this->monday();
    $sunday = $monday->addDays(6);
    $today = \App\Support\LocalTime::now()->toDateString();
    $days = $this->days();
    $statusClass = ['planned' => 'aw-badge--warn', 'ready' => 'aw-badge--info', 'published' => 'aw-badge--ok', 'skipped' => ''];
@endphp
<x-filament-panels::page>
    <div class="aw-stack">
        <div class="aw-toolbar aw-row aw-between">
            <div class="aw-row">
                <x-filament::button color="gray" size="sm" wire:click="shift(-1)" icon="heroicon-o-chevron-left" aria-label="Săptămâna anterioară" />
                <x-filament::button color="gray" size="sm" wire:click="thisWeek">Săptămâna aceasta</x-filament::button>
                <x-filament::button color="gray" size="sm" wire:click="shift(1)" icon="heroicon-o-chevron-right" aria-label="Săptămâna următoare" />
                <h2 class="aw-h" style="margin:0 0 0 .5rem;font-size:1.125rem" aria-live="polite">
                    {{ $monday->day }} {{ $monthNames[$monday->month] }} – {{ $sunday->day }} {{ $monthNames[$sunday->month] }} {{ $sunday->year }}
                </h2>
            </div>
            <span class="aw-muted">Descărcați imaginile, copiați textul, publicați din aplicația rețelei, apoi apăsați „Publicată”.</span>
        </div>

        @foreach ($days as $date => $posts)
            @php $d = \Carbon\CarbonImmutable::parse($date); @endphp
            <section class="aw-card aw-social-day @if ($date === $today) is-today @endif" aria-label="{{ $dayNames[$d->isoWeekday()] }}, {{ $d->day }} {{ $monthNames[$d->month] }}">
                <header class="aw-social-day__date">
                    <span class="aw-social-day__num">{{ $d->day }}</span>
                    <span>{{ $dayNames[$d->isoWeekday()] }}<br><span class="aw-muted">{{ $monthNames[$d->month] }}</span></span>
                    @if ($date === $today)<span class="aw-badge aw-badge--info">Azi</span>@endif
                </header>
                <div class="aw-social-day__posts">
                    @forelse ($posts as $post)
                        @php $media = $post->media(); @endphp
                        <article class="aw-social-post" wire:key="post-{{ $post->id }}">
                            <div class="aw-row aw-between">
                                <div class="aw-row" style="gap:.5rem">
                                    <strong>{{ $post->title }}</strong>
                                    <span class="aw-badge {{ $statusClass[$post->status] ?? '' }}">{{ \App\Modules\Marketing\Models\SocialPost::STATUSES[$post->status] ?? $post->status }}</span>
                                    @foreach ((array) $post->channels as $channel)
                                        <span class="aw-badge">{{ \App\Modules\Marketing\Models\SocialPost::CHANNELS[$channel] ?? $channel }}</span>
                                    @endforeach
                                    @if ($post->publish_time)<span class="aw-muted">ora sugerată {{ $post->publish_time }}</span>@endif
                                </div>
                                <div class="aw-row" style="gap:.5rem">
                                    <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-arrow-down-tray" :href="route('admin.social.bundle', $post)">Descarcă tot</x-filament::button>
                                    <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-pencil-square" :href="route('filament.admin.resources.social-posts.edit', $post)">Editează</x-filament::button>
                                    @if ($post->status === 'published')
                                        <x-filament::button size="sm" color="gray" wire:click="reopen({{ $post->id }})">Redeschide</x-filament::button>
                                    @else
                                        <x-filament::button size="sm" color="success" icon="heroicon-o-check-circle" wire:click="markPublished({{ $post->id }})">Am publicat</x-filament::button>
                                    @endif
                                </div>
                            </div>

                            @if ($media->isNotEmpty())
                                <div class="aw-social-media">
                                    @foreach ($media as $image)
                                        <figure>
                                            <a href="{{ route('admin.social.image', [$image, $post->format === 'story' || $post->format === 'reel' ? 'story' : 'portrait']) }}?preview=1" target="_blank" rel="noopener">
                                                <img src="{{ $image->urlForWidth(480, ['jpg', 'webp']) }}" alt="{{ $image->altText() }}" loading="lazy">
                                            </a>
                                            <figcaption>
                                                @foreach ($this->formats() as $key => $format)
                                                    <a class="aw-link" href="{{ route('admin.social.image', [$image, $key]) }}">{{ $format['label'] }}</a>
                                                @endforeach
                                            </figcaption>
                                        </figure>
                                    @endforeach
                                </div>
                            @endif

                            <div x-data="{ copied: false, copy() { const t = this.$refs.text; (navigator.clipboard ? navigator.clipboard.writeText(t.value) : Promise.reject()).catch(() => { t.select(); document.execCommand('copy'); }).finally(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); }); } }">
                                <textarea x-ref="text" class="aw-social-text" rows="{{ min(14, max(5, substr_count($post->fullText(), "\n") + 2)) }}" readonly aria-label="Textul postării">{{ $post->fullText() }}</textarea>
                                <div class="aw-row" style="gap:.5rem;margin-top:.5rem">
                                    <x-filament::button size="sm" icon="heroicon-o-clipboard-document" x-on:click="copy()">Copiază textul</x-filament::button>
                                    <span x-show="copied" x-transition class="aw-muted" role="status">Copiat.</span>
                                    <span class="aw-muted">{{ mb_strlen($post->fullText()) }} / 2.200 caractere</span>
                                </div>
                            </div>
                            @if ($post->notes)<p class="aw-muted" style="margin-top:.5rem">Notă: {{ $post->notes }}</p>@endif
                        </article>
                    @empty
                        <p class="aw-muted">Nicio postare. Folosiți „Propune postări pentru zilele libere” sau „Postare nouă”.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
