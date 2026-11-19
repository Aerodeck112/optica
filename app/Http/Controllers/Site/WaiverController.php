<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\SignWaiverRequest;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Booking\Services\PlayerWaiverService;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Cms\Services\SafeMarkdown;
use App\Support\Locales;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Declarația de participare: din linkul rezervării (semnat) sau din codul QR de la recepție.
 * După semnare, formularul se golește pentru următorul participant din același grup.
 */
class WaiverController extends Controller
{
    public function __construct(protected readonly PlayerWaiverService $waivers) {}

    public function show(Request $request, ?string $code = null)
    {
        $booking = $code ? Booking::query()->where('code', $code)->firstOrFail() : null;

        $prefill = $booking && $request->boolean('eu') ? array_filter([
            'signer_name' => $booking->contact_name,
            'email' => $booking->contact_email,
            'phone' => $booking->contact_phone,
        ]) : [];

        return $this->formView($booking, $request->fullUrl(), [
            'prefill' => $prefill,
            'seo' => Seo::make(__('site.waiver.title'), __('site.waiver.intro'))->noindex()
                ->alternates($booking ? [] : collect(Locales::enabled())->mapWithKeys(fn ($l) => [$l => lroute('waiver.show', [], $l)])->all()),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    protected function formView(?Booking $booking, string $action, array $extra = []): Response
    {
        $document = $this->waivers->document();
        $locale = app()->getLocale();

        return response()->view('site.waiver.form', $extra + [
            'booking' => $booking,
            'progress' => $booking ? $this->waivers->progress($booking) : null,
            'document' => $document,
            'body' => $document ? SafeMarkdown::render(LegalController::fillPlaceholders((string) $document->getTranslation('body', $locale))) : null,
            'sessionDate' => $booking?->starts_at ? LocalTime::localDate($booking->starts_at) : LocalTime::now()->toDateString(),
            'action' => $action,
            'privacy' => LegalDocument::current('privacy'),
            'seo' => Seo::make(__('site.waiver.title'), __('site.waiver.intro'))->noindex(),
        ])->header('Referrer-Policy', 'no-referrer');
    }

    public function store(SignWaiverRequest $request, ?string $code = null)
    {
        $booking = $code ? Booking::query()->where('code', $code)->firstOrFail() : null;
        if (! $booking && filled($request->input('booking_code'))) {
            $booking = Booking::query()->where('code', strtoupper(trim((string) $request->input('booking_code'))))->first();
            if (! $booking) {
                return back()->withInput($request->except('signature'))->withErrors(['booking_code' => __('site.waiver.booking_unknown')]);
            }
        }
        if ($rejected = $this->precheck($request, $booking)) {
            return $rejected;
        }

        try {
            $waivers = $this->signRequest($request, $booking, $code ? 'online' : 'reception');
        } catch (RuntimeException) {
            return back()->withInput($request->except('signature'))->withErrors(['participant_name' => __('site.waiver.unavailable')]);
        }

        return back()->with('waiver_signed', $waivers->pluck('participant_name')->implode(', '));
    }

    /** Verificările de dinaintea semnării: anti-spam, semnătură desenată, cine semnează pentru cine (adult / minor). */
    protected function precheck(SignWaiverRequest $request, ?Booking $booking): ?RedirectResponse
    {
        $reject = fn (array $errors) => back()->withInput($request->except('signature'))->withErrors($errors);
        if ($request->isSpam()) {
            return back()->withInput()->withErrors(['participant_name' => __('site.forms.sent_error')]);
        }
        if (! $request->signatureIsValid()) {
            return $reject(['signature' => __('site.waiver.signature_missing')]);
        }
        if (! $request->isFamily()) {
            if ($this->waivers->isMinor((string) $request->input('birth_date'), $booking) && blank($request->input('guardian_name'))) {
                return $reject(['guardian_name' => __('site.waiver.guardian_required')]);
            }

            return null;
        }
        if ($request->boolean('self') && $this->waivers->isMinor((string) $request->input('signer_birth_date'), $booking)) {
            return $reject(['signer_birth_date' => __('site.waiver.family.signer_minor')]);
        }
        foreach ((array) $request->input('children', []) as $i => $child) {
            if (! $this->waivers->isMinor((string) ($child['birth_date'] ?? ''), $booking)) {
                return $reject(["children.{$i}.birth_date" => __('site.waiver.family.child_adult', ['name' => $child['name'] ?? ''])]);
            }
        }

        return null;
    }

    /**
     * O declarație pentru fiecare participant din formular, toate odată (aceeași semnătură și același semnatar).
     *
     * @return Collection<int, PlayerWaiver>
     */
    protected function signRequest(SignWaiverRequest $request, ?Booking $booking, string $source): Collection
    {
        $family = $request->isFamily();
        $signer = trim((string) $request->input('signer_name'));
        $common = $request->only(['signer_name', 'email', 'signature', 'marketing_email', 'guardian_phone', 'screen_display']) + ['locale' => app()->getLocale()];
        $meta = ['ip' => $request->ip(), 'user_agent' => $request->userAgent(), 'source' => $source];

        return DB::transaction(fn () => collect($request->participants())->map(fn (array $p) => $this->waivers->sign($common + [
            'participant_name' => $p['participant_name'],
            'birth_date' => $p['birth_date'],
            'nickname' => $p['nickname'],
            'avatar_key' => $p['avatar_key'],
            'guardian_name' => $family ? $signer : $request->input('guardian_name'),
            'guardian_relationship' => $request->input('guardian_relationship') ?: ($family ? __('site.waiver.family.relationship_default') : null),
            // acordul de imagine îl dă doar adultul pentru el însuși
            'photo_consent' => $p['is_self'] || ! $family ? $request->input('photo_consent') : false,
        ], $booking, $meta)));
    }
}
