<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\SignWaiverRequest;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\PlayerWaiverService;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Cms\Services\SafeMarkdown;
use App\Support\Locales;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Declarația de participare: din linkul rezervării (semnat) sau din codul QR de la recepție.
 * După semnare, formularul se golește pentru următorul participant din același grup.
 */
class WaiverController extends Controller
{
    public function __construct(private readonly PlayerWaiverService $waivers) {}

    public function show(Request $request, ?string $code = null)
    {
        $booking = $code ? Booking::query()->where('code', $code)->firstOrFail() : null;
        $document = $this->waivers->document();
        $locale = app()->getLocale();

        return response()->view('site.waiver.form', [
            'booking' => $booking,
            'progress' => $booking ? $this->waivers->progress($booking) : null,
            'document' => $document,
            'body' => $document ? SafeMarkdown::render(LegalController::fillPlaceholders((string) $document->getTranslation('body', $locale))) : null,
            'sessionDate' => $booking?->starts_at ? LocalTime::localDate($booking->starts_at) : LocalTime::now()->toDateString(),
            'action' => $request->fullUrl(),
            'privacy' => LegalDocument::current('privacy'),
            'seo' => Seo::make(__('site.waiver.title'), __('site.waiver.intro'))->noindex()
                ->alternates($booking ? [] : collect(Locales::enabled())->mapWithKeys(fn ($l) => [$l => lroute('waiver.show', [], $l)])->all()),
        ])->header('Referrer-Policy', 'no-referrer');
    }

    public function store(SignWaiverRequest $request, ?string $code = null)
    {
        if ($request->isSpam()) {
            return back()->withInput()->withErrors(['participant_name' => __('site.forms.sent_error')]);
        }
        if (! $request->signatureIsValid()) {
            return back()->withInput($request->except('signature'))->withErrors(['signature' => __('site.waiver.signature_missing')]);
        }

        $booking = $code ? Booking::query()->where('code', $code)->firstOrFail() : null;
        if (! $booking && filled($request->input('booking_code'))) {
            $booking = Booking::query()->where('code', strtoupper(trim((string) $request->input('booking_code'))))->first();
            if (! $booking) {
                return back()->withInput($request->except('signature'))->withErrors(['booking_code' => __('site.waiver.booking_unknown')]);
            }
        }
        if ($this->waivers->isMinor((string) $request->input('birth_date'), $booking) && blank($request->input('guardian_name'))) {
            return back()->withInput($request->except('signature'))->withErrors(['guardian_name' => __('site.waiver.guardian_required')]);
        }

        try {
            $waiver = $this->waivers->sign($request->only([
                'participant_name', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'signer_name', 'email', 'signature', 'marketing_email', 'photo_consent',
                'nickname', 'avatar_key', 'screen_display',
            ]) + ['birth_date' => (string) $request->input('birth_date'), 'locale' => app()->getLocale()], $booking, [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => $code ? 'online' : 'reception',
            ]);
        } catch (RuntimeException) {
            return back()->withInput($request->except('signature'))->withErrors(['participant_name' => __('site.waiver.unavailable')]);
        }

        return back()->with('waiver_signed', $waiver->participant_name);
    }
}
