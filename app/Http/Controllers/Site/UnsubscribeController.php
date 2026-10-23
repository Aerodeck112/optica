<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\ConsentService;
use Illuminate\Http\Request;

/**
 * Dezabonare prin link semnat. GET afișează confirmarea (clienții de e-mail care pre-încarcă linkuri nu dezabonează
 * accidental); POST — inclusiv „one-click” din antetul List-Unsubscribe — retrage consimțământul.
 */
class UnsubscribeController extends Controller
{
    public function show(Customer $customer, string $channel)
    {
        abort_unless(in_array($channel, ['email', 'sms'], true), 404);

        return view('site.unsubscribe', [
            'customer' => $customer,
            'channel' => $channel,
            'done' => false,
            'seo' => Seo::make('Dezabonare')->noindex(),
            'showMobileBook' => false,
        ]);
    }

    public function confirm(Request $request, Customer $customer, string $channel, ConsentService $consents)
    {
        abort_unless(in_array($channel, ['email', 'sms'], true), 404);

        $consents->withdraw($customer, $channel, 'unsubscribe_link', [
            'ip_hash' => hash('sha256', $request->ip().config('app.key')),
            'one_click' => $request->has('List-Unsubscribe'),
            'at' => now()->toIso8601String(),
        ]);

        return view('site.unsubscribe', [
            'customer' => $customer,
            'channel' => $channel,
            'done' => true,
            'seo' => Seo::make('Dezabonare')->noindex(),
            'showMobileBook' => false,
        ]);
    }
}
