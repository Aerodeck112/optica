@extends('site.layout')

@section('content')
<header class="page-hero">
    <div class="wrap cluster" style="justify-content:space-between">
        <div>
            <h1>{{ __('site.account.title') }}</h1>
            <p>{{ $customer->fullName() }}</p>
        </div>
        <form method="post" action="{{ route('account.logout') }}">@csrf<button class="btn btn--ghost btn--small" type="submit">{{ __('site.account.logout') }}</button></form>
    </div>
</header>

<section class="section section--tight">
    <div class="wrap account-grid">
        <nav aria-label="{{ __('site.account.title') }}">
            <ul class="account-nav" role="list">
                <li><a href="#rezervari">{{ __('site.account.bookings') }}</a></li>
                @if ($points !== null)<li><a href="#puncte">{{ __('site.account.points') }}</a></li>@endif
                <li><a href="#comunicari">{{ __('site.account.preferences') }}</a></li>
                <li><a href="#date">{{ __('site.account.privacy') }}</a></li>
            </ul>
        </nav>

        <div class="stack" style="--stack: var(--space-8)">
            @if (session('status'))<div class="alert alert--ok" role="status">{{ session('status') }}</div>@endif

            <section id="rezervari">
                <h2 style="font-size:var(--fs-lg)">{{ __('site.account.bookings') }}</h2>
                @if ($bookings->isEmpty())
                    <p class="muted mt-4">{{ __('site.account.no_bookings') }} <a href="{{ lroute('booking.show') }}">{{ __('site.book_cta') }}</a></p>
                @else
                    <div style="overflow-x:auto" class="mt-5">
                        <table class="table">
                            <thead><tr><th scope="col">{{ __('site.booking.code') }}</th><th scope="col">{{ __('site.booking.summary_experience') }}</th><th scope="col">{{ __('site.booking.summary_when') }}</th><th scope="col">{{ __('site.booking.status') }}</th><th scope="col"><span class="visually-hidden">Acțiuni</span></th></tr></thead>
                            <tbody>
                                @foreach ($bookings as $booking)
                                    <tr>
                                        <td>{{ $booking->code }}</td>
                                        <td>{{ $booking->package?->name }}</td>
                                        <td>{{ $booking->localStart() ?? '—' }}</td>
                                        <td>{{ $booking->statusLabel() }}</td>
                                        <td>
                                            @if ($booking->isOpen())
                                                <form method="post" action="{{ route('account.manage-link', $booking) }}">@csrf<button class="btn btn--small btn--ghost" type="submit">{{ __('site.booking.manage') }}</button></form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($points !== null)
                <section id="puncte">
                    <h2 style="font-size:var(--fs-lg)">{{ __('site.account.points') }}</h2>
                    <p class="package__price mt-4">{{ $points }}</p>
                    @if ($customer->referral_code)<p class="mt-4">{{ __('site.account.referral') }}: <strong>{{ $customer->referral_code }}</strong></p>@endif
                </section>
            @endif

            <section id="comunicari">
                <h2 style="font-size:var(--fs-lg)">{{ __('site.account.preferences') }}</h2>
                <form method="post" action="{{ route('account.preferences') }}" class="mt-5">
                    @csrf
                    <label class="check"><input type="checkbox" name="marketing_email" value="1" @checked($consents['email'])><span>{{ __('site.booking.marketing_email') }}</span></label>
                    <label class="check"><input type="checkbox" name="marketing_sms" value="1" @checked($consents['sms'])><span>{{ __('site.booking.marketing_sms') }}</span></label>
                    <button class="btn btn--ghost mt-5" type="submit">{{ __('site.cookies.save') }}</button>
                </form>
            </section>

            <section id="date">
                <h2 style="font-size:var(--fs-lg)">{{ __('site.account.privacy') }}</h2>
                <p class="mt-4"><a class="btn btn--ghost" href="{{ lroute('account.show') }}?action=export">{{ __('site.account.export') }}</a></p>
                <p class="muted small mt-5">{{ __('site.account.erase_text') }}</p>
                @if ($openRequest)
                    <p class="alert mt-4">{{ __('site.account.erase_submitted') }}</p>
                @else
                    <form method="post" action="{{ route('account.erasure') }}" class="mt-4" data-confirm="{{ __('site.account.erase') }}?">@csrf<button class="btn btn--ghost" type="submit">{{ __('site.account.erase') }}</button></form>
                @endif
            </section>
        </div>
    </div>
</section>
@endsection
