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
                           <span class="aw-badge {{ $found->payment_status === 'paid' ? 'aw-badge--ok' : 'aw-badge--warn' }}">{{ $found->paymentStatusLabel() }} · rest {{ money($found->balanceMinor()) }}</span></p>
                        @if ($found->starts_at && \App\Support\LocalTime::localDate($found->starts_at) !== \App\Support\LocalTime::now()->toDateString())
                            <p class="aw-badge aw-badge--warn">Atenție: rezervarea nu este pentru azi.</p>
                        @endif
                    </div>
                    <div class="aw-row">
                        @if ($found->canTransitionTo('checked_in'))
                            <x-filament::button size="lg" color="success" wire:click="checkIn({{ $found->id }})" icon="heroicon-o-check">Confirmă sosirea</x-filament::button>
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
                    <thead><tr><th>Ora</th><th>Client</th><th>Pers.</th><th>Pachet</th><th>Stare</th><th><span class="sr-only">Acțiuni</span></th></tr></thead>
                    <tbody>
                        @foreach ($arrivals as $b)
                            <tr>
                                <td style="font-weight:600">{{ \App\Support\LocalTime::format($b->starts_at, 'H:i') }}</td>
                                <td><a class="aw-link" href="{{ $bookingUrl($b) }}">{{ $b->contact_name }}</a><br><span class="aw-muted">{{ $b->code }}</span></td>
                                <td>{{ $b->participants }}</td>
                                <td>{{ $b->package?->getTranslation('name', 'ro') }}</td>
                                <td><span class="aw-badge {{ $b->status === 'checked_in' ? 'aw-badge--info' : ($b->status === 'confirmed' ? 'aw-badge--ok' : 'aw-badge--warn') }}">{{ $b->statusLabel() }}</span></td>
                                <td>@if ($b->canTransitionTo('checked_in'))<x-filament::button size="sm" wire:click="checkIn({{ $b->id }})">Check-in</x-filament::button>@endif</td>
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
