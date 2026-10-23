@extends('site.layout')

@section('content')
<section class="section">
    <div class="wrap--narrow" style="max-width:32rem">
        <h1>{{ __('site.account.login_title') }}</h1>
        <p class="muted mt-4">{{ __('site.account.login_text') }}</p>
        @if (session('status'))<div class="alert alert--ok mt-5" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="alert alert--error mt-5" role="alert">{{ $errors->first() }}</div>@endif
        <form method="post" action="{{ route('forms.account-login') }}" class="mt-6" data-guard>
            @csrf
            <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
            @include('site.partials.field', ['name' => 'email', 'type' => 'email', 'label' => __('site.booking.email'), 'required' => true, 'autocomplete' => 'email'])
            <button class="btn btn--primary mt-5" type="submit" data-busy="{{ __('site.booking.submitting') }}">{{ __('site.account.login_submit') }}</button>
        </form>
    </div>
</section>
@endsection
