<?php

namespace App\Http\Requests;

use App\Modules\Loyalty\Services\VoucherOrderService;
use Illuminate\Validation\Rule;

class StoreVoucherOrderRequest extends PublicFormRequest
{
    public function rules(): array
    {
        return [
            'product' => ['required', Rule::in(array_keys(VoucherOrderService::PRODUCTS))],
            'buyer_name' => ['required', 'string', 'min:3', 'max:120'],
            'buyer_email' => ['required', 'email:rfc', 'max:190'],
            'buyer_phone' => ['nullable', 'string', 'min:6', 'max:40', 'regex:/^[0-9+()\s.\-]+$/'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:300'],
            'accept_terms' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return [
            'product' => 'voucherul',
            'buyer_name' => 'numele tău',
            'buyer_email' => 'e-mailul',
            'buyer_phone' => 'telefonul',
            'recipient_name' => 'numele destinatarului',
            'message' => 'mesajul',
            'accept_terms' => 'condițiile voucherelor',
        ];
    }
}
