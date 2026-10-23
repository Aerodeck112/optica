<?php

namespace App\Http\Requests;

use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Package;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends PublicFormRequest
{
    public function rules(): array
    {
        return [
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'participants' => ['required', 'integer', 'min:1', 'max:200'],
            'age_groups' => ['nullable', 'array'],
            'age_groups.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'starts_at' => ['nullable', 'date'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'preferred_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'preferred_time' => ['nullable', 'in:morning,afternoon,evening,flexible'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'min:0', 'max:20'],
            'contact_name' => ['required', 'string', 'min:3', 'max:120'],
            'contact_email' => ['required', 'email:rfc', 'max:190'],
            'contact_phone' => ['required', 'string', 'min:6', 'max:40', 'regex:/^[0-9+()\s.\-]+$/'],
            'company_name' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['nullable', 'in:at_location,bank_transfer,online'],
            'accept_terms' => ['accepted'],
            'accept_minors' => ['nullable', 'boolean'],
            'marketing_email' => ['nullable', 'boolean'],
            'marketing_sms' => ['nullable', 'boolean'],
            'hold_id' => ['nullable', 'uuid'],
            'hold_token' => ['nullable', 'string', 'max:64'],
            'game_id' => ['nullable', 'integer', 'exists:games,id'],
            'locale' => ['nullable', 'string', 'max:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.accepted' => __('site.booking.accept_terms', ['terms' => __('site.booking.terms_link'), 'policy' => __('site.booking.policy_link')]),
            'contact_phone.regex' => 'Introdu un număr de telefon valid.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $groups = array_map('intval', (array) $this->input('age_groups', []));
            $total = array_sum($groups);
            if ($total > 0 && $total !== (int) $this->input('participants')) {
                $validator->errors()->add('age_groups', __('site.booking.age_groups_mismatch'));
            }
            $minors = ($groups['under_12'] ?? 0) + ($groups['12_17'] ?? 0);
            $package = Package::query()->find($this->integer('package_id'));
            if ($minors > 0 && in_array('minors', app(BookingService::class)->requiredPolicyTypes($groups), true) && ! $this->boolean('accept_minors')) {
                $validator->errors()->add('accept_minors', __('site.booking.accept_minors', ['minors' => __('site.booking.minors_link')]));
            }
            if ($package && ! $package->supportsInstantBooking() && ! $this->filled('preferred_date')) {
                $validator->errors()->add('preferred_date', __('validation.required', ['attribute' => __('site.booking.preferred_date')]));
            }
        }];
    }

    /** Ora aleasă: din câmpul ISO (JS) sau din dată + oră locală (formular fără JS). */
    public function startsAt(): ?CarbonImmutable
    {
        if ($this->filled('starts_at')) {
            return CarbonImmutable::parse($this->input('starts_at'))->utc();
        }
        if ($this->filled('date') && $this->filled('time')) {
            return LocalTime::fromLocal($this->input('date'), $this->input('time'));
        }

        return null;
    }

    /** Pentru cereri: data dorită + o oră reprezentativă a intervalului (informativ, nu ocupă arena). */
    public function preferredStart(): ?CarbonImmutable
    {
        if (! $this->filled('preferred_date')) {
            return null;
        }
        $time = ['morning' => '10:00', 'afternoon' => '14:00', 'evening' => '18:00'][$this->input('preferred_time')] ?? '12:00';

        return LocalTime::fromLocal($this->input('preferred_date'), $time);
    }
}
