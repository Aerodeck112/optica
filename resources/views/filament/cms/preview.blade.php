<div x-data="{ locale: 'ro', device: 'desktop' }" class="aw-preview-wrap">
    <div class="aw-toolbar">
        <div class="aw-seg" role="group" aria-label="Dispozitiv">
            <button type="button" x-on:click="device = 'desktop'" x-bind:aria-pressed="device === 'desktop'">Desktop</button>
            <button type="button" x-on:click="device = 'mobile'" x-bind:aria-pressed="device === 'mobile'">Mobil (390 px)</button>
        </div>
        <label class="aw-inline-label">Limba
            <select x-model="locale">
                @foreach ($urls as $locale => $url)
                    <option value="{{ $locale }}">{{ strtoupper($locale) }}</option>
                @endforeach
            </select>
        </label>
        <span class="aw-muted">Se afișează ciorna salvată. Linkul de previzualizare expiră în 30 de minute.</span>
    </div>
    <div class="aw-preview">
        @foreach ($urls as $locale => $url)
            <template x-if="locale === '{{ $locale }}'">
                <iframe src="{{ $url }}" title="Previzualizare {{ strtoupper($locale) }}" x-bind:class="device === 'mobile' ? 'aw-frame-mobile' : 'aw-frame-desktop'"></iframe>
            </template>
        @endforeach
    </div>
</div>
