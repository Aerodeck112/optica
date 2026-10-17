<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Access\TwoFactor;
use App\Support\Audit\AuditLogger;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request) && ! $request->user()) {
            return redirect()->route('crm.login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $this->pendingUser($request) ?? $request->user();
        if ($user === null) {
            return redirect()->route('crm.login');
        }

        $key = '2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        if (! $this->twoFactor->verify($user->two_factor_secret, $request->string('code'))) {
            RateLimiter::hit($key, 300);
            $this->audit->log('auth.two_factor_failed', user: $user);
            throw ValidationException::withMessages(['code' => __('auth.two_factor.invalid')]);
        }
        RateLimiter::clear($key);

        if (! $request->user()) {
            Auth::login($user, (bool) $request->session()->pull('auth.remember', false));
            $request->session()->forget(TwoFactor::SESSION_PENDING_USER);
            $request->session()->regenerate();
            app(LoginController::class)->completeLogin($request, $user);
        }
        $request->session()->put(TwoFactor::SESSION_PASSED, true);

        return redirect()->intended(route('crm.dashboard'));
    }

    public function setup(Request $request): View
    {
        $secret = $request->session()->get('auth.two_factor_setup_secret') ?? $this->twoFactor->generateSecret();
        $request->session()->put('auth.two_factor_setup_secret', $secret);

        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));
        $qr = $writer->writeString($this->twoFactor->otpauthUrl($request->user(), $secret));

        return view('auth.two-factor-setup', [
            'qr' => $qr,
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'required' => $this->twoFactor->isRequiredFor($request->user()),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $secret = $request->session()->get('auth.two_factor_setup_secret');
        if (! $secret || ! $this->twoFactor->verify($secret, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => __('auth.two_factor.invalid')]);
        }

        $user = $request->user();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('auth.two_factor_setup_secret');
        $request->session()->put(TwoFactor::SESSION_PASSED, true);
        $this->audit->log('auth.two_factor_enabled', $user);

        return redirect()->route('crm.dashboard')->with('status', __('auth.two_factor.enabled'));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(TwoFactor::SESSION_PENDING_USER);

        return $id ? User::where('is_active', true)->find($id) : null;
    }
}
