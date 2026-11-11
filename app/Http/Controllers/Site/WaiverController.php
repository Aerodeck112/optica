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

        return $this->formView($booking, $request->fullUrl(), [
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
            $waiver = $this->signRequest($request, $booking, $code ? 'online' : 'reception');
        } catch (RuntimeException) {
            return back()->withInput($request->except('signature'))->withErrors(['participant_name' => __('site.waiver.unavailable')]);
        }

        return back()->with('waiver_signed', $waiver->participant_name);
    }

    /** Verificările de dinaintea semnării: anti-spam, semnătură desenată, adult care semnează pentru un minor. */
    protected function precheck(SignWaiverRequest $request, ?Booking $booking): ?RedirectResponse
    {
        if ($request->isSpam()) {
            return back()->withInput()->withErrors(['participant_name' => __('site.forms.sent_error')]);
        }
        if (! $request->signatureIsValid()) {
            return back()->withInput($request->except('signature'))->withErrors(['signature' => __('site.waiver.signature_missing')]);
        }
        if ($this->waivers->isMinor((string) $request->input('birth_date'), $booking) && blank($request->input('guardian_name'))) {
            return back()->withInput($request->except('signature'))->withErrors(['guardian_name' => __('site.waiver.guardian_required')]);
        }

        return null;
    }

    protected function signRequest(SignWaiverRequest $request, ?Booking $booking, string $source): PlayerWaiver
    {
        return $this->waivers->sign($request->only([
            'participant_name', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'signer_name', 'email', 'signature', 'marketing_email', 'photo_consent',
            'nickname', 'avatar_key', 'screen_display',
        ]) + ['birth_date' => (string) $request->input('birth_date'), 'locale' => app()->getLocale()], $booking, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'source' => $source,
        ]);
    }
}
