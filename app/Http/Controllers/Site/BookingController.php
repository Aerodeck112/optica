<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Core\Models\Integration;
use App\Modules\Core\Models\OpeningHour;
use App\Modules\Core\Models\ScheduleException;
use App\Modules\Core\Settings\SettingsRepository;
use App\Support\LocalTime;
use App\Support\Locales;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly BookingService $bookings,
    ) {}

    public function show(Request $request)
    {
        $locale = app()->getLocale();
        $packages = Package::query()->active()->publishedIn($locale)->with('addons')->get();

        $selected = null;
        if ($slug = $request->query('pachet')) {
            $selected = $packages->first(fn (Package $p) => $p->getTranslation('slug', $locale) === $slug);
        }
        $game = null;
        if (! $selected && ($gameSlug = $request->query('joc'))) {
            $game = Game::query()->where("slug->{$locale}", $gameSlug)->first();
            $selected = $game ? $packages->first(fn (Package $p) => $p->games->contains('id', $game->id)) : null;
        }
        if (! $selected && $packages->count() === 1) {
            $selected = $packages->first();
        }

        $seo = Seo::make(__('site.booking.title'), __('site.booking.intro'));
        $seo->canonical = lroute('booking.show');
        $seo->alternates(collect(Locales::enabled())->mapWithKeys(fn ($l) => [$l => lroute('booking.show', [], $l)])->all());

        return view('site.booking.show', [
            'packages' => $packages,
            'selected' => old('package_id') ? $packages->firstWhere('id', (int) old('package_id')) : $selected,
            'game' => $game,
            'config' => $this->clientConfig($packages, $locale),
            'ageGroups' => (array) $this->settings->get('booking.age_groups'),
            'paymentMethods' => $this->paymentMethods(),
            'policies' => $this->policyLinks($locale),
            'onlineEnabled' => $this->settings->bool('booking.online_enabled'),
            'seo' => $seo,
            'showMobileBook' => false,
        ]);
    }

    public function store(StoreBookingRequest $request)
    {
        $locale = Locales::isSupported($request->input('locale')) ? $request->input('locale') : Locales::DEFAULT;
        app()->setLocale($locale);

        if ($request->isSpam()) {
            // răspuns identic cu o eroare de validare, fără a indica motivul
            return back()->withInput()->withErrors(['contact_name' => __('site.booking.error_generic')]);
        }

        $package = Package::query()->active()->findOrFail($request->integer('package_id'));
        $instant = $package->supportsInstantBooking() && $this->settings->bool('booking.online_enabled');
        $startsAt = $instant ? $request->startsAt() : $request->preferredStart();

        if ($instant && ! $startsAt) {
            return back()->withInput()->withErrors(['starts_at' => __('site.booking.choose_time')]);
        }

        $ageGroups = array_filter(array_map('intval', (array) $request->input('age_groups', [])));
        $accepted = array_keys(array_filter([
            'terms' => $request->boolean('accept_terms'),
            'booking_policy' => $request->boolean('accept_terms'),
            'minors' => $request->boolean('accept_minors'),
        ]));

        $notes = trim((string) $request->input('notes'));
        if (! $instant && $request->filled('preferred_time')) {
            $notes = trim(__('site.booking.preferred_time').': '.(__('site.booking.preferred_time_options')[$request->input('preferred_time')] ?? '').".\n".$notes);
        }

        try {
            $result = $this->bookings->create(new BookingData(
                package: $package,
                startsAt: $startsAt,
                participants: $request->integer('participants'),
                contactName: $request->string('contact_name')->trim()->toString(),
                contactEmail: $request->string('contact_email')->trim()->toString(),
                contactPhone: $request->string('contact_phone')->trim()->toString(),
                source: 'web',
                ageGroups: $ageGroups,
                addons: array_map('intval', (array) $request->input('addons', [])),
                companyName: $request->input('company_name') ?: null,
                customerNotes: $notes ?: null,
                paymentMethod: $request->input('payment_method') ?: 'at_location',
                holdPublicId: $request->input('hold_id') ?: null,
                holdToken: $request->input('hold_token') ?: null,
                acceptedPolicyTypes: $accepted,
                marketingConsent: ['email' => $request->boolean('marketing_email'), 'sms' => $request->boolean('marketing_sms')],
                consentContext: [
                    'form' => 'booking',
                    'text_email' => __('site.booking.marketing_email'),
                    'text_sms' => __('site.booking.marketing_sms'),
                    'ip_hash' => hash('sha256', $request->ip().config('app.key')),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
                    'at' => now()->toIso8601String(),
                ],
                locale: $locale,
                gameId: $request->integer('game_id') ?: null,
                forceRequest: ! $instant,
                analyticsClientRef: substr(hash('sha256', $request->session()->getId()), 0, 24),
            ));
        } catch (BookingException $e) {
            $field = in_array($e->reason, ['slot_unavailable', 'hold_expired'], true) ? 'starts_at' : ($e->reason === 'policies' ? 'accept_terms' : ($e->reason === 'participants' ? 'participants' : 'contact_name'));

            return back()->withInput()->withErrors([$field => $e->getMessage()]);
        }

        return redirect()->to(lroute('booking.confirmation', ['code' => $result['booking']->code], $locale).'?t='.$result['manage_token'])
            ->with('booking_just_created', true);
    }

    public function confirmation(Request $request, string $code)
    {
        $booking = Booking::query()->where('code', $code)->with(['package', 'items'])->first();
        abort_unless($booking && hash_equals((string) $booking->manage_token_hash, hash('sha256', (string) $request->query('t'))), 404);

        $seo = Seo::make(__('site.booking.code').' '.$booking->code)->noindex();

        return response()->view('site.booking.confirmation', [
            'booking' => $booking,
            'token' => (string) $request->query('t'),
            'justCreated' => (bool) $request->session()->get('booking_just_created'),
            'seo' => $seo,
            'showMobileBook' => false,
        ])->header('Referrer-Policy', 'no-referrer');
    }

    private function clientConfig($packages, string $locale): array
    {
        $today = LocalTime::now();
        $maxDays = (int) $this->settings->int('booking.max_advance_days', 90);
        $closedWeekdays = OpeningHour::query()->get()->filter(fn ($h) => $h->is_closed || ! $h->opens_at)->pluck('weekday')->map(fn ($d) => (int) $d)->values()->all();
        $configuredDays = OpeningHour::query()->pluck('weekday')->map(fn ($d) => (int) $d)->all();
        $closedWeekdays = array_values(array_unique(array_merge($closedWeekdays, array_diff(range(1, 7), $configuredDays))));
        $closedDates = ScheduleException::query()
            ->where('is_closed', true)
            ->whereBetween('date', [$today->toDateString(), $today->addDays($maxDays)->toDateString()])
            ->pluck('date')->map(fn ($d) => $d->toDateString())->all();

        return [
            'locale' => $locale,
            'today' => $today->toDateString(),
            'max_date' => $today->addDays($maxDays)->toDateString(),
            'closed_weekdays' => $closedWeekdays,
            'closed_dates' => $closedDates,
            'endpoints' => [
                'slots' => route('api.booking.slots'),
                'quote' => route('api.booking.quote'),
                'hold' => route('api.booking.hold'),
                'release' => route('api.booking.release'),
            ],
            'packages' => $packages->map(fn (Package $p) => [
                'id' => $p->id,
                'name' => $p->getTranslation('name', $locale),
                'type' => $p->type,
                'mode' => $p->supportsInstantBooking() && $this->settings->bool('booking.online_enabled') ? 'instant' : 'request',
                'min_participants' => $p->min_participants ?: 1,
                'max_participants' => $p->max_participants ?: 50,
                'addons' => $p->addons->where('is_active', true)->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->getTranslation('name', $locale),
                    'description' => $a->getTranslation('description', $locale, false) ?: null,
                    'price_label' => $a->price_minor !== null ? Money::format($a->price_minor).($a->pricing_model === 'per_person' ? ' '.__('site.packages.per_person') : '') : null,
                ])->values()->all(),
            ])->values()->all(),
            'i18n' => [
                'weekdays' => __('site.booking.weekdays'),
                'prev_month' => __('site.booking.prev_month'),
                'next_month' => __('site.booking.next_month'),
                'closed' => __('site.hours.closed'),
                'closed_day' => __('site.booking.closed_day'),
                'no_slots' => __('site.booking.no_slots'),
                'choose_time' => __('site.booking.choose_time'),
                'choose_time_first' => __('site.booking.choose_time'),
                'loading' => __('site.loading'),
                'slot_held' => __('site.booking.slot_held_timer'),
                'slot_hold_expired' => __('site.booking.slot_hold_expired'),
                'no_extras' => __('site.booking.no_extras'),
                'participants_hint' => __('site.booking.participants_hint'),
                'summary_title' => __('site.booking.summary_title'),
                'summary_experience' => __('site.booking.summary_experience'),
                'summary_when' => __('site.booking.summary_when'),
                'summary_participants' => __('site.booking.summary_participants'),
                'summary_slot_duration' => __('site.booking.summary_slot_duration'),
                'summary_play_time' => __('site.booking.summary_play_time'),
                'summary_total' => __('site.booking.summary_total'),
                'summary_deposit' => __('site.booking.summary_deposit'),
                'summary_price_tbd' => __('site.booking.summary_price_tbd'),
                'submit_instant' => __('site.booking.submit_instant'),
                'submit_request' => __('site.booking.submit_request'),
                'submitting' => __('site.booking.submitting'),
                'error_generic' => __('site.booking.error_generic'),
                'error_rate' => __('site.booking.error_rate'),
            ],
        ];
    }

    private function paymentMethods(): array
    {
        $enabled = (array) $this->settings->get('booking.payment_methods', ['at_location']);
        $labels = __('site.booking.payment_methods');
        $methods = [];
        foreach ($enabled as $method) {
            if ($method === 'online' && ! Integration::query()->where('key', 'payments')->first()?->isUsable()) {
                continue;
            }
            if ($method === 'bank_transfer' && ! $this->settings->filled('company.iban')) {
                continue;
            }
            $methods[$method] = $labels[$method] ?? $method;
        }

        return $methods ?: ['at_location' => $labels['at_location']];
    }

    private function policyLinks(string $locale): array
    {
        $out = [];
        foreach (['terms', 'booking_policy', 'minors', 'privacy'] as $type) {
            $doc = LegalDocument::current($type);
            if ($doc) {
                $out[$type] = lroute('legal.show', ['slug' => $doc->getTranslation('slug', $locale, true)]);
            }
        }

        return $out;
    }
}
