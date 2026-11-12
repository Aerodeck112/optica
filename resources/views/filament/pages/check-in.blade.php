<x-filament-panels::page>
    <div class="aw-stack">
        <form wire:submit="lookup" class="aw-card aw-stack" x-data="awQrScanner($wire)">
            <label for="checkin-code" class="aw-h">Scanează codul QR sau tastează codul rezervării</label>
            <div class="aw-row">
                <input id="checkin-code" type="text" wire:model="code" autocomplete="off" autocapitalize="characters" autofocus placeholder="ex. AWK7M3QX9"
                       class="aw-select aw-grow" style="font-size:1.25rem;padding:.75rem 1rem;letter-spacing:.06em;min-width:14rem">
                <x-filament::button type="submit" size="lg">Caută</x-filament::button>
                <template x-if="supported">
                    <x-filament::button type="button" color="gray" size="lg" icon="heroicon-o-camera" x-on:click="toggle()"><span x-text="active ? 'Oprește camera' : 'Scanează cu camera'"></span></x-filament::button>
                </template>
            </div>
            <video x-ref="video" x-show="active" playsinline muted style="max-width:22rem;border-radius:.75rem;background:#000"></video>
            <p class="aw-muted" x-show="!supported">Scanarea cu camera nu este disponibilă în acest browser; folosește un scaner sau tastează codul.</p>
            @if ($message)<p class="aw-badge aw-badge--bad" role="alert" style="font-size:.9rem">{{ $message }}</p>@endif
        </form>

        @if ($found)
            <section class="aw-card aw-stack" aria-live="polite">
                <div class="aw-row aw-between">
                    <div>
                        <h3 class="aw-h" style="font-size:1.25rem">{{ $found->contact_name }} · {{ $found->participants }} participanți</h3>
                        <p class="aw-muted">{{ $found->code }} · {{ $found->package?->getTranslation('name', 'ro') }} · {{ $found->localStart() ?? 'fără dată' }}</p>
                        <p><span class="aw-badge {{ in_array($found->status, ['confirmed', 'checked_in']) ? 'aw-badge--ok' : 'aw-badge--warn' }}">{{ $found->statusLabel() }}</span>
                           <span class="aw-badge {{ $found->payment_status === 'paid' ? 'aw-badge--ok' : 'aw-badge--warn' }}">{{ $found->paymentStatusLabel() }} · rest {{ money($found->balanceMinor()) }}</span>
                           <span class="aw-badge {{ $found->waivers_count >= $found->participants ? 'aw-badge--ok' : 'aw-badge--warn' }}">Declarații: {{ $found->waivers_count }}/{{ $found->participants }}</span>
                           @if ($waiversUrl)<a class="aw-link" href="{{ $waiversUrl($found) }}">vezi declarațiile</a>@endif</p>
                        @if ($found->promoCode?->per_participant)
                            <p class="aw-badge {{ $found->waivers_count >= $found->participants ? 'aw-badge--ok' : 'aw-badge--warn' }}" style="font-size:.9rem;margin-top:.35rem">
                                Oferta {{ $found->promoCode->code }} acoperă {{ $found->participants }} {{ $found->participants === 1 ? 'participant' : 'participanți' }}: fiecare o confirmă semnând declarația ({{ $found->waivers_count }}/{{ $found->participants }} confirmați).
                            </p>
                            @foreach ($offerRepeats as $repeat)
                                <p class="aw-badge aw-badge--bad" role="alert" style="font-size:.9rem;margin-top:.35rem">
                                    {{ $repeat['name'] }} a mai beneficiat de oferta {{ $found->promoCode->code }} (rezervarea {{ $repeat['booking'] }}, {{ $repeat['date'] }}): pentru această persoană se încasează tariful obișnuit.
                                </p>
                            @endforeach
                        @endif
                        @if ($found->waivers_count < $found->participants)
                            <p class="aw-muted">Cei care nu au completat declarația o pot face acum: pe tabletă, cu un cod de 6 cifre, sau scanând codul QR de la recepție.</p>
                        @endif
                        @if ($activeCodes->isNotEmpty())
                            <div class="aw-row" style="gap:.5rem;margin-top:.5rem;flex-wrap:wrap" aria-label="Coduri pentru tabletă nefolosite">
                                @foreach ($activeCodes as $waiverCode)
                                    <span class="aw-badge aw-badge--info" style="font-size:1.1rem;letter-spacing:.08em;font-variant-numeric:tabular-nums">@if ($waiverCode->waiver_card_id){{ $waiverCode->card?->label() }}@else J{{ $waiverCode->position }} · {{ $waiverCode->formatted() }}@endif</span>
                                @endforeach
                                @if ($activeCodes->whereNull('waiver_card_id')->isNotEmpty())<a class="aw-link" href="{{ $codesPrintUrl($found) }}" target="_blank" rel="noopener">tipărește bilețelele</a>@endif
                                @if ($activeCodes->whereNotNull('waiver_card_id')->isNotEmpty())<button type="button" class="aw-link" wire:click="releaseCards({{ $found->id }})">ia înapoi cardurile nefolosite</button>@endif
                            </div>
                        @endif
                        @if ($canCodes && $found->waivers_count < $found->participants)
                            <form wire:submit="assignCard" class="aw-row" style="gap:.5rem;margin-top:.75rem">
                                <label for="card-input" class="aw-muted">Dai un card anume? Scanează-i codul de bare:</label>
                                <input id="card-input" type="text" wire:model="cardInput" autocomplete="off" placeholder="cod de bare sau AWC-007" class="aw-select" style="min-width:12rem">
                                <x-filament::button type="submit" size="sm" color="gray">Dă cardul</x-filament::button>
                            </form>
                        @endif
                        @if ($found->starts_at && \App\Support\LocalTime::localDate($found->starts_at) !== \App\Support\LocalTime::now()->toDateString())
                            <p class="aw-badge aw-badge--warn">Atenție: rezervarea nu este pentru azi.</p>
                        @endif
                    </div>
                    <div class="aw-row">
                        @if ($found->canTransitionTo('checked_in'))
                            <x-filament::button size="lg" color="success" wire:click="checkIn({{ $found->id }})" icon="heroicon-o-check">Confirmă sosirea</x-filament::button>
                        @endif
                        @if ($canCodes && $found->waivers_count < $found->participants)
                            <x-filament::button size="lg" color="gray" wire:click="issueCodes({{ $found->id }})" icon="heroicon-o-device-tablet">Coduri pentru tabletă</x-filament::button>
                        @endif
                        @if ($canSessions && $found->starts_at)
                            <x-filament::button size="lg" color="primary" wire:click="openSession({{ $found->id }})" icon="heroicon-o-play-circle">Participanți și sesiune de joc</x-filament::button>
                        @endif
                        <x-filament::button tag="a" color="gray" size="lg" :href="$bookingUrl($found)">Deschide rezervarea</x-filament::button>
                    </div>
                </div>
            </section>
        @endif

        <section class="aw-card">
            <h3 class="aw-h">Sosiri de azi</h3>
            @if ($arrivals->isEmpty())
                <p class="aw-muted">Nu există rezervări pentru azi.</p>
            @else
                <table class="aw-table">
                    <thead><tr><th>Ora</th><th>Client</th><th>Pers.</th><th>Declarații</th><th>Pachet</th><th>Stare</th><th><span class="sr-only">Acțiuni</span></th></tr></thead>
                    <tbody>
                        @foreach ($arrivals as $b)
                            <tr>
                                <td style="font-weight:600">{{ \App\Support\LocalTime::format($b->starts_at, 'H:i') }}</td>
                                <td><a class="aw-link" href="{{ $bookingUrl($b) }}">{{ $b->contact_name }}</a><br><span class="aw-muted">{{ $b->code }}</span></td>
                                <td>{{ $b->participants }}</td>
                                <td>@if ($waiversUrl)<a class="aw-link" href="{{ $waiversUrl($b) }}">@endif<span class="aw-badge {{ $b->waivers_count >= $b->participants ? 'aw-badge--ok' : 'aw-badge--warn' }}">{{ $b->waivers_count }}/{{ $b->participants }}</span>@if ($waiversUrl)</a>@endif</td>
                                <td>{{ $b->package?->getTranslation('name', 'ro') }}</td>
                                <td><span class="aw-badge {{ $b->status === 'checked_in' ? 'aw-badge--info' : ($b->status === 'confirmed' ? 'aw-badge--ok' : 'aw-badge--warn') }}">{{ $b->statusLabel() }}</span></td>
                                <td style="display:flex;gap:.5rem;flex-wrap:wrap">@if ($b->canTransitionTo('checked_in'))<x-filament::button size="sm" wire:click="checkIn({{ $b->id }})">Check-in</x-filament::button>@endif
                                    @if ($canCodes && $b->waivers_count < $b->participants)<x-filament::button size="sm" color="gray" wire:click="issueCodes({{ $b->id }})" icon="heroicon-o-device-tablet">Coduri</x-filament::button>@endif
                                    @if ($canSessions)<x-filament::button size="sm" color="gray" wire:click="openSession({{ $b->id }})">Sesiune</x-filament::button>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    <script>
        window.awQrScanner = window.awQrScanner || function ($wire) {
            return {
                supported: 'BarcodeDetector' in window && !!navigator.mediaDevices,
                active: false, stream: null, timer: null,
                async toggle() { this.active ? this.stop() : await this.start(); },
                async start() {
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                        this.$refs.video.srcObject = this.stream; await this.$refs.video.play(); this.active = true;
                        const detector = new BarcodeDetector({ formats: ['qr_code'] });
                        this.timer = setInterval(async () => {
                            const codes = await detector.detect(this.$refs.video).catch(() => []);
                            if (codes.length) { this.stop(); $wire.set('code', codes[0].rawValue); $wire.lookup(); }
                        }, 350);
                    } catch (e) { this.stop(); alert('Camera nu poate fi pornită: ' + e.message); }
                },
                stop() { clearInterval(this.timer); this.stream?.getTracks().forEach((t) => t.stop()); this.active = false; },
            };
        };
    </script>
</x-filament-panels::page>
