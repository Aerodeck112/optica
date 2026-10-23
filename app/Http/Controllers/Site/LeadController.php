<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Modules\Crm\Services\LeadService;

class LeadController extends Controller
{
    public function store(StoreLeadRequest $request, LeadService $leads)
    {
        if ($request->isSpam()) {
            return back()->withInput()->withErrors(['contact_name' => __('site.forms.sent_error')]);
        }

        $lead = $leads->create([
            'type' => $request->input('lead_type'),
            'contact_name' => $request->input('contact_name'),
            'contact_email' => $request->input('contact_email'),
            'contact_phone' => $request->input('contact_phone'),
            'company_name' => $request->input('company_name'),
            'participants' => $request->integer('participants') ?: null,
            'preferred_date' => $request->input('preferred_date'),
            'preferred_time' => $request->input('preferred_time'),
            'message' => $request->input('message'),
            'locale' => $request->input('locale', 'ro'),
        ], 'web', [
            'email' => $request->boolean('marketing_email'),
            'sms' => $request->boolean('marketing_sms'),
        ], [
            'form' => 'lead_'.$request->input('lead_type'),
            'text_email' => __('site.booking.marketing_email'),
            'text_sms' => __('site.booking.marketing_sms'),
            'ip_hash' => hash('sha256', $request->ip().config('app.key')),
            'at' => now()->toIso8601String(),
        ]);

        return back()->with(['lead_sent' => $lead->reference, 'lead_type' => $lead->type]);
    }
}
