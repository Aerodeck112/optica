@extends('site.layout')

@section('content')
<section class="section">
    <div class="wrap--narrow">
        <div class="panel">
            <h1 style="font-size: var(--fs-xl)">{{ __('site.voucher_order.title') }}</h1>
            <p class="muted mt-4">{{ __('site.voucher_order.intro') }}</p>

            @if (! $online || $products === [])
                <div class="notice mt-6">
                    <x-aw-icon name="info"/>
                    <div class="stack">
                        <p>{{ __('site.voucher_order.unavailable') }}</p>
                        <p><a class="btn btn--primary" href="{{ app(\App\Modules\Cms\Services\LinkResolver::class)->url('page:contact') }}">{{ __('site.voucher_order.write_us') }}</a></p>
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
                    <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
                    @include('site.partials.form-guard', ['id' => 'voucher'])
                    <fieldset style="border:0;padding:0">
                        <legend class="field__label">{{ __('site.voucher_order.choose') }}</legend>
                        <div class="choice-grid mt-4">
                            @foreach ($products as $key => $product)
                                <label class="choice">
                                    <input type="radio" name="product" value="{{ $key }}" @checked($selected === $key) required>
                                    <span class="choice__title">{{ __('site.voucher_order.products.'.$key.'.label') }} — {{ money($product['price_minor']) }}</span>
                                    <span class="choice__meta">{{ __('site.voucher_order.products.'.$key.'.text') }}. {{ __('site.voucher_order.includes') }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <h2 class="mt-7" style="font-size: var(--fs-lg)">{{ __('site.voucher_order.your_details') }}</h2>
                    <div class="field-row mt-5">
                        @include('site.partials.field', ['name' => 'buyer_name', 'label' => __('site.voucher_order.buyer_name'), 'required' => true, 'autocomplete' => 'name', 'id' => 'voucher-buyer-name'])
                        @include('site.partials.field', ['name' => 'buyer_email', 'type' => 'email', 'label' => __('site.voucher_order.buyer_email'), 'required' => true, 'autocomplete' => 'email', 'id' => 'voucher-buyer-email'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'buyer_phone', 'type' => 'tel', 'label' => __('site.voucher_order.buyer_phone'), 'autocomplete' => 'tel', 'id' => 'voucher-buyer-phone'])
                    </div>

                    <h2 class="mt-7" style="font-size: var(--fs-lg)">{{ __('site.voucher_order.personalise') }}</h2>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'recipient_name', 'label' => __('site.voucher_order.recipient'), 'id' => 'voucher-recipient'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'message', 'type' => 'textarea', 'label' => __('site.voucher_order.message'), 'hint' => __('site.voucher_order.message_hint'), 'id' => 'voucher-message'])
                    </div>

                    <div class="notice mt-6">
                        <x-aw-icon name="info"/>
                        <div class="stack small">
                            <p><strong>{{ __('site.voucher_order.terms_title') }}</strong></p>
                            <ul>
                                @foreach (__('site.voucher_order.terms') as $term)<li>{{ $term }}</li>@endforeach
                            </ul>
                        </div>
                    </div>
                    <label class="check mt-5"><input type="checkbox" name="accept_terms" value="1" required @checked(old('accept_terms'))><span>{{ __('site.voucher_order.accept') }}</span></label>
                    @php $privacy = \App\Modules\Cms\Models\LegalDocument::current('privacy'); @endphp
                    <p class="small muted mt-4">
                        {{ __('site.voucher_order.privacy') }}
                        @if ($privacy)
                            {!! __('site.voucher_order.privacy_link', ['link' => '<a href="'.e(lroute('legal.show', ['slug' => $privacy->getTranslation('slug', app()->getLocale(), true)])).'">'.e(__('site.voucher_order.privacy_link_text')).'</a>']) !!}
                        @endif
                        {{ __('site.voucher_order.card') }}
                    </p>
                    <button type="submit" class="btn btn--primary mt-6" data-busy="{{ __('site.voucher_order.busy') }}">{{ __('site.voucher_order.submit') }}</button>
                </form>
            @endif
        </div>
    </div>
</section>
@endsection
