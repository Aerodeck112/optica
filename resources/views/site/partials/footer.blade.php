<footer class="site-footer">
    <div class="wrap">
        <div class="site-footer__grid">
            <div class="stack" style="--stack: var(--space-4)">
                <img src="/media/brand/aw-logo-stacked.svg" alt="Another World" width="120" height="88" loading="lazy">
                <p class="muted">{{ __('site.footer.tagline') }}</p>
                @if ($social)
                    <div class="social">
                        @foreach ($social as $network => $url)
                            <a href="{{ $url }}" rel="noopener" target="_blank" aria-label="{{ ucfirst($network) }}"><x-aw-icon :name="$network"/></a>
                        @endforeach
                    </div>
                @endif
            </div>

            <nav aria-labelledby="footer-explore">
                <h2 id="footer-explore">{{ __('site.footer.explore') }}</h2>
                <ul role="list">
                    @foreach ($menus['footer_explore'] ?? [] as $item)
                        <li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>

            <div>
                <h2>{{ __('site.footer.contact') }}</h2>
                <ul role="list">
                    @isset($contact['address'])
                        <li>{{ $contact['address'] }}, {{ $contact['city'] }}</li>
                    @else
                        <li>{{ $contact['city'] }}</li>
                    @endisset
                    @isset($contact['phone'])<li><a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></li>@endisset
                    @isset($contact['email'])<li><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>@endisset
                    @isset($contact['maps_url'])<li><a href="{{ $contact['maps_url'] }}" rel="noopener" target="_blank">{{ __('site.contact.directions') }}</a></li>@endisset
                </ul>
                <h2 class="mt-5">{{ __('site.footer.hours') }}</h2>
                @if ($hours)
                    <dl class="footer-hours">
                        @foreach ($hours as $day)
                            <dt>{{ $day['label'] }}</dt>
                            <dd>{{ $day['closed'] ? __('site.hours.closed') : $day['opens'].'–'.$day['closes'] }}</dd>
                        @endforeach
                    </dl>
                @else
                    <p class="muted">{{ __('site.hours.not_configured') }}</p>
                @endif
            </div>

            <nav aria-labelledby="footer-legal">
                <h2 id="footer-legal">{{ __('site.footer.legal') }}</h2>
                <ul role="list">
                    @foreach ($menus['footer_info'] ?? [] as $item)
                        <li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                    @endforeach
                    @foreach ($legalLinks as $link)
                        <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                    @endforeach
                    <li><a href="#" data-consent="preferences">{{ __('site.footer.cookie_settings') }}</a></li>
                    <li>
                        <a class="sal-badge" href="{{ setting('legal.sal_url') }}" rel="noopener" target="_blank">
                            @if ($pictogram = filled_setting('legal.sal_pictogram'))
                                <img src="{{ $pictogram }}" alt="{{ __('site.footer.sal') }}" width="250" height="50" loading="lazy">
                            @else
                                {{ __('site.footer.sal') }}
                            @endif
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

        <div class="site-footer__bottom">
            <p>© {{ now()->year }} {{ $company['name'] ?? __('site.brand') }}. {{ __('site.footer.rights') }}
                @if (! empty($company['tax_id'])) CUI {{ $company['tax_id'] }}@endif
                @if (! empty($company['reg_no'])), {{ $company['reg_no'] }}@endif
            </p>
            <p>{{ __('site.footer.franchise_note') }}</p>
        </div>
    </div>
</footer>
