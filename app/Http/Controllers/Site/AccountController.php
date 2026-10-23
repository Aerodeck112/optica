<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\Booking;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Core\Models\DataRequest;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\CustomerLoginToken;
use App\Modules\Crm\Services\ConsentService;
use App\Modules\Crm\Services\DataExportService;
use App\Modules\Notifications\Services\Notifier;
use App\Support\ContactNormalizer;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly ConsentService $consents,
        private readonly SettingsRepository $settings,
    ) {}

    public function show(Request $request)
    {
        $customer = Auth::guard('customer')->user();
        $seo = Seo::make(__('site.account.title'))->noindex();

        if (! $customer) {
            return response()->view('site.account.login', ['seo' => $seo, 'showMobileBook' => false]);
        }

        if ($request->query('action') === 'export') {
            return response()->json(app(DataExportService::class)->export($customer), 200, [
                'Content-Disposition' => 'attachment; filename="datele-mele-another-world.json"',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return response()->view('site.account.show', [
            'customer' => $customer,
            'bookings' => $customer->bookings()->with('package')->latest('starts_at')->limit(50)->get(),
            'points' => $this->settings->bool('loyalty.enabled') ? $customer->loyaltyBalance() : null,
            'ledger' => $this->settings->bool('loyalty.enabled') ? $customer->loyaltyEntries()->limit(20)->get() : collect(),
            'consents' => [
                'email' => $customer->hasMarketingConsent('email'),
                'sms' => $customer->hasMarketingConsent('sms'),
            ],
            'openRequest' => DataRequest::query()->where('customer_id', $customer->id)->whereIn('status', ['received', 'verified'])->exists(),
            'seo' => $seo,
            'showMobileBook' => false,
        ]);
    }

    /** Trimite linkul de autentificare. Răspunsul este identic dacă adresa există sau nu (fără enumerare). */
    public function sendLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190'], 'locale' => ['nullable', 'string', 'max:5']]);
        $locale = Locales::isSupported($data['locale'] ?? null) ? $data['locale'] : Locales::DEFAULT;
        $email = ContactNormalizer::email($data['email']);

        $key = 'magic:'.hash('sha256', (string) $email);
        if ($email && ! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 900);
            $customer = Customer::query()->where('email_normalized', $email)->whereNull('merged_into_id')->whereNull('anonymized_at')->first();
            if ($customer) {
                $token = Str::random(48);
                CustomerLoginToken::query()->create([
                    'customer_id' => $customer->id,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addMinutes(30),
                    'ip_hash' => hash('sha256', $request->ip().config('app.key')),
                ]);
                $this->notifier->sendToCustomer($customer, 'account.login_link', 'email', [
                    'login_url' => route('account.consume', ['token' => $token]),
                ], 'service', 'login:'.$customer->id.':'.now()->timestamp);
            }
        }

        return redirect()->to(lroute('account.show', [], $locale))->with('status', __('site.account.login_sent'));
    }

    public function consumeLink(Request $request, string $token)
    {
        $record = CustomerLoginToken::query()->where('token_hash', hash('sha256', $token))->first();

        if (! $record || $record->used_at || $record->expires_at->isPast() || ! $record->customer || $record->customer->isAnonymized()) {
            return redirect()->to(lroute('account.show'))->withErrors(['email' => __('site.account.login_invalid')]);
        }

        $record->forceFill(['used_at' => now()])->save();
        Auth::guard('customer')->login($record->customer);
        $request->session()->regenerate();

        return redirect()->to(lroute('account.show', [], $record->customer->locale ?: 'ro'));
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(lroute('page'));
    }

    public function updatePreferences(Request $request)
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($customer, 403);
        $proof = ['form' => 'account', 'ip_hash' => hash('sha256', $request->ip().config('app.key')), 'at' => now()->toIso8601String()];

        foreach (['email', 'sms'] as $channel) {
            $wants = $request->boolean('marketing_'.$channel);
            $has = $customer->hasMarketingConsent($channel);
            if ($wants && ! $has) {
                $this->consents->grant($customer, $channel, 'account', $proof + ['text' => __('site.booking.marketing_'.$channel)]);
            } elseif (! $wants && $has) {
                $this->consents->withdraw($customer, $channel, 'account', $proof);
            }
        }

        return back()->with('status', __('site.account.saved'));
    }

    public function requestErasure(Request $request)
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($customer, 403);

        DataRequest::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'type' => 'erasure', 'status' => 'received'],
            ['channel' => 'account', 'due_on' => now()->addMonth()->toDateString()],
        );

        return back()->with('status', __('site.account.erase_submitted'));
    }

    /** Regenerează linkul de gestionare al unei rezervări și îl trimite pe e-mail (linkul vechi devine invalid). */
    public function resendManageLink(Request $request, Booking $booking)
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($customer && $booking->customer_id === $customer->id, 404);

        $token = $booking->rotateManageToken();
        $this->notifier->sendToCustomer($customer, 'booking.manage_link', 'email', [
            'booking' => $booking,
            'manage_url' => lroute('booking.manage', ['token' => $token], $booking->locale ?: 'ro'),
        ], 'service', 'manage-link:'.$booking->id.':'.now()->timestamp, $booking);

        return back()->with('status', __('site.account.saved'));
    }
}
