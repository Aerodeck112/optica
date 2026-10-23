<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Modules\Crm\Services\LeadService;

/**
 * Mesajele din formularul de contact intră în CRM ca cereri de tip „other”, cu istoric și responsabil.
 */
class ContactController extends Controller
{
    public function store(ContactRequest $request, LeadService $leads)
    {
        if ($request->isSpam()) {
            return back()->withInput()->withErrors(['name' => __('site.forms.sent_error')]);
        }

        $leads->create([
            'type' => 'other',
            'contact_name' => $request->input('name'),
            'contact_email' => $request->input('email'),
            'contact_phone' => $request->input('phone'),
            'message' => $request->input('message'),
            'locale' => $request->input('locale', 'ro'),
        ], 'web', [
            'email' => $request->boolean('marketing_email'),
            'sms' => $request->boolean('marketing_sms'),
        ], [
            'form' => 'contact',
            'text_email' => __('site.booking.marketing_email'),
            'ip_hash' => hash('sha256', $request->ip().config('app.key')),
            'at' => now()->toIso8601String(),
        ]);

        return back()->with('contact_sent', true);
    }
}
