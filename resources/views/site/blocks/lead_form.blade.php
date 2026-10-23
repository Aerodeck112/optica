@php $type = $b['type']; @endphp
<section class="section" id="{{ $b['anchor'] ?: 'cerere' }}">
    <div class="wrap--narrow">
        <div class="panel">
            <h2>{{ $b['heading'] ?: __('site.lead.title_'.$type) }}</h2>
            @if ($b['intro'])<p class="muted mt-4">{{ $b['intro'] }}</p>@endif

            @if (session('lead_sent') && session('lead_type') === $type)
                <div class="alert alert--ok mt-5" role="status">
                    <p>{{ __('site.lead.sent') }}</p>
                    <p class="small mt-4">{{ __('site.lead.reference', ['ref' => session('lead_sent')]) }}</p>
                </div>
            @else
                @if ($errors->any() && old('lead_type') === $type)
                    <div class="alert alert--error mt-5" role="alert">{{ __('site.forms.sent_error') }}</div>
                @endif
                <form class="mt-6" method="post" action="{{ route('forms.lead') }}" data-guard novalidate>
                    @csrf
                    <input type="hidden" name="lead_type" value="{{ $type }}">
                    <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
                    @include('site.partials.form-guard', ['id' => 'lead-'.$type])
                    <div class="field-row">
                        @include('site.partials.field', ['name' => 'contact_name', 'label' => __('site.booking.name'), 'required' => true, 'autocomplete' => 'name', 'id' => 'lead-'.$type.'-name'])
                        @if ($type === 'corporate')
                            @include('site.partials.field', ['name' => 'company_name', 'label' => __('site.booking.company'), 'autocomplete' => 'organization', 'id' => 'lead-'.$type.'-company'])
                        @endif
                    </div>
                    <div class="field-row mt-5">
                        @include('site.partials.field', ['name' => 'contact_email', 'type' => 'email', 'label' => __('site.booking.email'), 'required' => true, 'autocomplete' => 'email', 'id' => 'lead-'.$type.'-email'])
                        @include('site.partials.field', ['name' => 'contact_phone', 'type' => 'tel', 'label' => __('site.booking.phone'), 'required' => true, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'id' => 'lead-'.$type.'-phone'])
                    </div>
                    <div class="field-row mt-5">
                        @include('site.partials.field', ['name' => 'participants', 'type' => 'number', 'label' => __('site.lead.participants'), 'required' => true, 'min' => 1, 'maxValue' => 300, 'inputmode' => 'numeric', 'id' => 'lead-'.$type.'-participants'])
                        @include('site.partials.field', ['name' => 'preferred_date', 'type' => 'date', 'label' => __('site.lead.preferred_date'), 'required' => true, 'min' => now()->toDateString(), 'id' => 'lead-'.$type.'-date'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'preferred_time', 'type' => 'select', 'label' => __('site.booking.preferred_time'), 'options' => __('site.booking.preferred_time_options'), 'id' => 'lead-'.$type.'-time'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'message', 'type' => 'textarea', 'label' => __('site.lead.message'), 'hint' => __('site.lead.message_hint'), 'id' => 'lead-'.$type.'-message'])
                    </div>
                    @include('site.partials.privacy-consents', ['prefix' => 'lead-'.$type])
                    <button type="submit" class="btn btn--primary mt-6" data-busy="{{ __('site.booking.submitting') }}">{{ __('site.lead.submit') }}</button>
                </form>
            @endif
        </div>
    </div>
</section>
