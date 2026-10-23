<?php

namespace App\Http\Requests;

class ContactRequest extends PublicFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.\-]+$/'],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            'marketing_email' => ['nullable', 'boolean'],
            'marketing_sms' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'max:5'],
        ];
    }
}
