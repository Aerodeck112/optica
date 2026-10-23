<section class="section" id="{{ $b['anchor'] ?: 'mesaj' }}">
    <div class="wrap--narrow">
        <div class="panel">
            <h2>{{ $b['heading'] }}</h2>
            @if ($b['intro'])<p class="muted mt-4">{{ $b['intro'] }}</p>@endif
            @if (session('contact_sent'))
                <div class="alert alert--ok mt-5" role="status">{{ __('site.contact.sent') }}</div>
            @else
                <form class="mt-6" method="post" action="{{ route('forms.contact') }}" data-guard novalidate>
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
                    @include('site.partials.form-guard', ['id' => 'contact'])
                    <div class="field-row">
                        @include('site.partials.field', ['name' => 'name', 'label' => __('site.booking.name'), 'required' => true, 'autocomplete' => 'name', 'id' => 'contact-name'])
                        @include('site.partials.field', ['name' => 'email', 'type' => 'email', 'label' => __('site.booking.email'), 'required' => true, 'autocomplete' => 'email', 'id' => 'contact-email'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'phone', 'type' => 'tel', 'label' => __('site.booking.phone'), 'autocomplete' => 'tel', 'id' => 'contact-phone'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'message', 'type' => 'textarea', 'label' => __('site.contact.message'), 'required' => true, 'hint' => __('site.booking.notes_hint'), 'id' => 'contact-message'])
                    </div>
                    @include('site.partials.privacy-consents', ['prefix' => 'contact'])
                    <button type="submit" class="btn btn--primary mt-6" data-busy="{{ __('site.booking.submitting') }}">{{ __('site.contact.submit') }}</button>
                </form>
            @endif
        </div>
    </div>
</section>
