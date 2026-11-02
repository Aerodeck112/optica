@extends('site.layout')

@section('content')
<section class="section">
    <div class="wrap--narrow">
        <div class="panel">
            <h1 style="font-size: var(--fs-xl)">Comandă un voucher cadou</h1>
            <p class="muted mt-4">Plătești online, iar voucherul ajunge pe e-mail, personalizat și gata de trimis sau de printat. Valabil 6 luni de la cumpărare.</p>

            @if (! $online || $products === [])
                <div class="notice mt-6">
                    <x-aw-icon name="info"/>
                    <div class="stack">
                        <p>Plata online pentru vouchere va fi disponibilă în curând. Până atunci, scrie-ne sau sună-ne și pregătim voucherul pentru tine.</p>
                        <p><a class="btn btn--primary" href="{{ app(\App\Modules\Cms\Services\LinkResolver::class)->url('page:contact') }}">Scrie-ne</a></p>
                    </div>
                </div>
            @else
                @if ($errors->any())
                    <div class="alert alert--error mt-5" role="alert" tabindex="-1">
                        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
                <form class="mt-6" method="post" action="{{ route('forms.voucher') }}" data-guard novalidate>
                    @csrf
                    @include('site.partials.form-guard', ['id' => 'voucher'])
                    <fieldset style="border:0;padding:0">
                        <legend class="field__label">Alege voucherul</legend>
                        <div class="choice-grid mt-4">
                            @foreach ($products as $key => $product)
                                <label class="choice">
                                    <input type="radio" name="product" value="{{ $key }}" @checked($selected === $key) required>
                                    <span class="choice__title">{{ $product['label'] }} — {{ money($product['price_minor']) }}</span>
                                    <span class="choice__meta">{{ $product['text'] }}. 60 de minute de VR multiplayer și acces la zona de PlayStation.</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <h2 class="mt-7" style="font-size: var(--fs-lg)">Datele tale</h2>
                    <div class="field-row mt-5">
                        @include('site.partials.field', ['name' => 'buyer_name', 'label' => 'Numele tău', 'required' => true, 'autocomplete' => 'name', 'id' => 'voucher-buyer-name'])
                        @include('site.partials.field', ['name' => 'buyer_email', 'type' => 'email', 'label' => 'E-mail (aici primești voucherul)', 'required' => true, 'autocomplete' => 'email', 'id' => 'voucher-buyer-email'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'buyer_phone', 'type' => 'tel', 'label' => 'Telefon', 'autocomplete' => 'tel', 'id' => 'voucher-buyer-phone'])
                    </div>

                    <h2 class="mt-7" style="font-size: var(--fs-lg)">Personalizare (opțional)</h2>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'recipient_name', 'label' => 'Pentru cine este voucherul', 'id' => 'voucher-recipient'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'message', 'type' => 'textarea', 'label' => 'Un scurt mesaj pe voucher', 'hint' => 'Cel mult 300 de caractere.', 'id' => 'voucher-message'])
                    </div>

                    <div class="notice mt-6">
                        <x-aw-icon name="info"/>
                        <div class="stack small">
                            <p><strong>Condiții pentru vouchere</strong></p>
                            <ul>
                                <li>Valabil 6 luni de la data cumpărării.</li>
                                <li>Rezervarea se face în avans, în funcție de disponibilitate, comunicând codul unic al voucherului.</li>
                                <li>Se folosește pentru experiența VR trecută pe voucher.</li>
                                <li>Nu se returnează și nu se poate schimba pe bani.</li>
                            </ul>
                        </div>
                    </div>
                    <label class="check mt-5"><input type="checkbox" name="accept_terms" value="1" required @checked(old('accept_terms'))><span>Am citit și accept condițiile pentru vouchere.</span></label>
                    @php $privacy = \App\Modules\Cms\Models\LegalDocument::current('privacy'); @endphp
                    <p class="small muted mt-4">
                        Folosim datele tale doar pentru comandă și trimiterea voucherului.
                        @if ($privacy) Detalii în <a href="{{ lroute('legal.show', ['slug' => $privacy->getTranslation('slug', 'ro', true)]) }}">politica de confidențialitate</a>. @endif
                        Plata se face pe pagina securizată a procesatorului de plăți; nu stocăm datele cardului.
                    </p>
                    <button type="submit" class="btn btn--primary mt-6" data-busy="Se deschide plata…">Continuă la plată</button>
                </form>
            @endif
        </div>
    </div>
</section>
@endsection
