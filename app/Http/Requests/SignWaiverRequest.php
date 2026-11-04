<?php

namespace App\Http\Requests;

class SignWaiverRequest extends PublicFormRequest
{
    public function rules(): array
    {
        return [
            'participant_name' => ['required', 'string', 'min:3', 'max:120'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'booking_code' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'guardian_name' => ['nullable', 'string', 'min:3', 'max:120'],
            'guardian_relationship' => ['nullable', 'string', 'max:60'],
            'guardian_phone' => ['nullable', 'string', 'min:6', 'max:40', 'regex:/^[0-9+()\s.\-]+$/'],
            'signer_name' => ['required', 'string', 'min:3', 'max:120'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:400000'],
            'accept' => ['accepted'],
            'marketing_email' => ['nullable', 'boolean'],
            'photo_consent' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'max:5'],
        ];
    }

    public function attributes(): array
    {
        return [
            'participant_name' => __('site.waiver.participant_name'),
            'birth_date' => __('site.waiver.birth_date'),
            'guardian_name' => __('site.waiver.guardian_name'),
            'guardian_phone' => __('site.waiver.guardian_phone'),
            'signer_name' => __('site.waiver.signer_name'),
            'signature' => __('site.waiver.signature'),
            'accept' => __('site.waiver.accept'),
        ];
    }

    public function messages(): array
    {
        return ['signature.required' => __('site.waiver.signature_missing')];
    }

    /** O semnătură reală: imagine PNG validă, nu un chenar gol. */
    public function signatureIsValid(): bool
    {
        $binary = base64_decode(substr((string) $this->input('signature'), strlen('data:image/png;base64,')), true);
        if ($binary === false || ! str_starts_with($binary, "\x89PNG") || strlen($binary) < 800) {
            return false;
        }
        $size = @getimagesizefromstring($binary);

        return is_array($size) && $size[0] >= 100 && $size[1] >= 50;
    }
}
