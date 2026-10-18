@php
    use App\Support\Ui\SiteUrl;
    $services = $catalog->services()->keyBy('code');
    // Each answer points to a service by its stable code; nothing is concluded about the person's health.
    $answers = [
        'glasses' => 'prescriptie-ochelari-lentile-contact',
        'child' => 'screening-oftalmologic',
        'check' => 'screening-oftalmologic',
        'pressure' => 'tonometrie',
        'retina' => 'fund-de-ochi-oct',
        'kids_myopia' => 'management-miopie-ambliopie',
    ];
@endphp
<div class="mt-8 rounded-2xl bg-white p-6 ring-1 ring-line" x-data="{ answer: null }">
    <fieldset>
        <legend class="font-semibold text-heading">{{ __('site.tools.guide_question') }}</legend>
        <div class="mt-4 grid gap-2 sm:grid-cols-2">
            @foreach (array_keys($answers) as $key)
                <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg px-3 ring-1 ring-line has-[:checked]:bg-mist has-[:checked]:ring-ink">
                    <input type="radio" name="guide" value="{{ $key }}" x-model="answer" class="h-4 w-4 text-ink">
                    <span>{{ __('site.tools.guide_answers.'.$key) }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>
    @foreach ($answers as $key => $code)
        @php($svc = $services[$code] ?? null)
        <div x-show="answer === '{{ $key }}'" x-cloak class="mt-6 rounded-xl bg-paper p-4" aria-live="polite">
            @if ($svc)
                <p>{{ __('site.tools.guide_suggestion') }} <a href="{{ SiteUrl::path('servicii/'.$svc->code) }}" class="font-semibold text-ink underline">{{ $svc->publicValue('name') }}</a>.</p>
                <a href="{{ SiteUrl::route('booking') }}" class="btn-primary mt-3">{{ __('site.book_online') }}</a>
            @else
                <p>{{ __('site.tools.guide_call') }}</p>
            @endif
        </div>
    @endforeach
    <p class="mt-6 rounded-lg bg-warn-bg px-4 py-3 text-sm text-warn">{{ __('site.tools.emergency') }}</p>
</div>
