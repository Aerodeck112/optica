<?php

namespace App\Http\Controllers\Site;

use App\Http\Requests\SignWaiverRequest;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\WaiverCode;
use App\Modules\Booking\Services\WaiverCodeException;
use App\Modules\Booking\Services\WaiverCodeService;
use App\Modules\Cms\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Tableta de la recepție: jucătorul tastează codul de 6 cifre primit, completează și semnează declarația,
 * apoi tableta revine singură la ecranul cu tastatura, golită pentru următorul jucător.
 * Pe tabletă nu se deschide nicio sesiune de administrare; codul este singura cheie, valabilă o singură dată.
 */
class WaiverTabletController extends WaiverController
{
    private const SESSION_KEY = 'waiver_tablet';

    /** După atâtea minute fără semnare, formularul se închide (jucătorul a plecat, tableta rămâne curată). */
    public const FORM_MINUTES = 20;

    public function keypad(): Response
    {
        return response()->view('site.waiver.tablet', [
            'kiosk' => true,
            'seo' => Seo::make(__('site.waiver.tablet.title'), __('site.waiver.tablet.lede'))->noindex(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function enter(Request $request, WaiverCodeService $codes): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $code = $codes->find((string) $request->input('code'));
        if (! $code) {
            return redirect(lroute('waiver.tablet'))->withErrors(['code' => __('site.waiver.tablet.invalid')]);
        }
        $request->session()->put(self::SESSION_KEY, ['id' => $code->id, 'at' => now()->getTimestamp()]);

        return redirect(lroute('waiver.tablet.form'));
    }

    public function form(Request $request): Response|RedirectResponse
    {
        $code = $this->current($request);
        if (! $code) {
            return $this->backToKeypad($request, __('site.waiver.tablet.expired'));
        }

        return $this->formView($code->booking, lroute('waiver.tablet.sign'), [
            'kiosk' => true,
            'tablet' => ['cancel' => lroute('waiver.tablet.cancel'), 'idle' => self::FORM_MINUTES * 60],
            'seo' => Seo::make(__('site.waiver.title'), __('site.waiver.intro'))->noindex(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sign(SignWaiverRequest $request, WaiverCodeService $codes): RedirectResponse
    {
        $code = $this->current($request);
        if (! $code) {
            return $this->backToKeypad($request, __('site.waiver.tablet.expired'));
        }
        if ($rejected = $this->precheck($request, $code->booking)) {
            return $rejected;
        }

        try {
            $waiver = $codes->redeem($code, fn (?Booking $booking) => $this->signRequest($request, $booking, 'tablet'));
        } catch (WaiverCodeException) {
            return $this->backToKeypad($request, __('site.waiver.tablet.used'));
        } catch (RuntimeException) {
            return back()->withInput($request->except('signature'))->withErrors(['participant_name' => __('site.waiver.unavailable')]);
        }
        $request->session()->forget(self::SESSION_KEY);

        return redirect(lroute('waiver.tablet'))->with('waiver_signed', $waiver->participant_name);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->forget('_old_input');

        return redirect(lroute('waiver.tablet'));
    }

    /** Codul tastat pe această tabletă, dacă e încă valabil și formularul nu a stat deschis prea mult. */
    private function current(Request $request): ?WaiverCode
    {
        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state) || now()->getTimestamp() - (int) ($state['at'] ?? 0) > self::FORM_MINUTES * 60) {
            return null;
        }
        $code = WaiverCode::query()->with('booking')->find((int) ($state['id'] ?? 0));

        return $code?->isActive() ? $code : null;
    }

    private function backToKeypad(Request $request, string $message): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect(lroute('waiver.tablet'))->withErrors(['code' => $message]);
    }
}
