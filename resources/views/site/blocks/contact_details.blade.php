<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap contact-grid">
        <div>
            @if ($b['heading'])<h2>{{ $b['heading'] }}</h2>@endif
            @if ($b['intro'])<p class="muted mt-4">{{ $b['intro'] }}</p>@endif
            <dl class="contact-list mt-6">
                <div><dt>{{ __('site.contact.address') }}</dt><dd>{{ $contact['address'] ?? '' }}{{ isset($contact['address']) ? ', ' : '' }}{{ $contact['city'] }}</dd></div>
                @isset($contact['phone'])<div><dt>{{ __('site.contact.phone') }}</dt><dd><a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></dd></div>@endisset
                @isset($contact['email'])<div><dt>{{ __('site.contact.email') }}</dt><dd><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></dd></div>@endisset
            </dl>
            <div class="cluster mt-6">
                @isset($contact['maps_url'])<a class="btn btn--ghost" href="{{ $contact['maps_url'] }}" rel="noopener" target="_blank"><x-aw-icon name="pin" style="width:1.1rem;height:1.1rem"/>{{ __('site.contact.directions') }}</a>@endisset
                @isset($contact['whatsapp'])<a class="btn btn--ghost" href="https://wa.me/{{ ltrim(preg_replace('/\D/', '', $contact['whatsapp']), '0') }}" rel="noopener" target="_blank"><x-aw-icon name="whatsapp" style="width:1.1rem;height:1.1rem"/>{{ __('site.contact.whatsapp') }}</a>@endisset
            </div>
        </div>
        <div class="panel">
            <h3>{{ __('site.footer.hours') }}</h3>
            @if ($hours)
                <dl class="footer-hours mt-4">
                    @foreach ($hours as $day)
                        <dt>{{ $day['label'] }}</dt>
                        <dd>{{ $day['closed'] ? __('site.hours.closed') : $day['opens'].'–'.$day['closes'] }}</dd>
                    @endforeach
                </dl>
                <p class="small muted mt-4">{{ __('site.hours.by_reservation') }}</p>
            @else
                <p class="muted mt-4">{{ __('site.hours.not_configured') }}</p>
            @endif
        </div>
    </div>
</section>
