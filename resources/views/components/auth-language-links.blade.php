{{-- Before login there is no profile yet: the language is chosen with ?lang= and kept in the session. --}}
<nav class="mt-10 flex gap-4 text-sm" aria-label="{{ __('crm.language') }}">
    @foreach (config('optica.locales') as $locale)
        <a href="{{ request()->fullUrlWithQuery(['lang' => $locale]) }}" lang="{{ $locale }}"
           @class(['font-semibold text-ink underline underline-offset-4' => app()->getLocale() === $locale, 'text-muted hover:text-ink' => app()->getLocale() !== $locale])
           @if (app()->getLocale() === $locale) aria-current="true" @endif>{{ __('crm.languages.'.$locale) }}</a>
    @endforeach
</nav>
