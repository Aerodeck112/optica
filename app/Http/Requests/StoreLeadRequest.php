<?php

namespace App\Http\Requests;

class StoreLeadRequest extends PublicFormRequest
{
    public function rules(): array
    {
        return [
            'lead_type' => ['required', 'in:birthday,corporate,private,group,other'],
            'contact_name' => ['required', 'string', 'min:3', 'max:120'],
            'contact_email' => ['required', 'email:rfc', 'max:190'],
            'contact_phone' => ['required', 'string', 'min:6', 'max:40', 'regex:/^[0-9+()\s.\-]+$/'],
            'company_name' => ['nullable', 'string', 'max:190'],
            'participants' => ['required', 'integer', 'min:1', 'max:300'],
            'preferred_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'preferred_time' => ['nullable', 'in:morning,afternoon,evening,flexible'],
            'message' => ['nullable', 'string', 'max:2000'],
            'marketing_email' => ['nullable', 'boolean'],
            'marketing_sms' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'max:5'],
        ];
    }
}
