<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\CustomerNote;
use App\Support\ContactNormalizer;
use Illuminate\Support\Str;

/**
 * Găsește sau creează clientul după e-mail/telefon normalizat. Nu suprascrie datele existente cu valori goale
 * și nu unește automat doi clienți diferiți: dacă e-mailul și telefonul indică persoane diferite, lasă o notă pentru verificare.
 */
class CustomerResolver
{
    public function resolve(array $data, string $source = 'web'): Customer
    {
        $email = ContactNormalizer::email($data['email'] ?? null);
        $phone = ContactNormalizer::phone($data['phone'] ?? null);

        $byEmail = $email ? Customer::query()->whereNull('merged_into_id')->whereNull('anonymized_at')->where('email_normalized', $email)->first() : null;
        $byPhone = $phone ? Customer::query()->whereNull('merged_into_id')->whereNull('anonymized_at')->where('phone_normalized', $phone)->first() : null;

        $customer = $byEmail ?? $byPhone;

        [$first, $last] = $this->splitName($data['name'] ?? null, $data['first_name'] ?? null, $data['last_name'] ?? null);

        if (! $customer) {
            $customer = Customer::query()->create([
                'type' => ! empty($data['company_name']) ? 'company' : 'person',
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email ? trim((string) $data['email']) : null,
                'email_normalized' => $email,
                'phone' => $phone ? trim((string) $data['phone']) : null,
                'phone_normalized' => $phone,
                'company_name' => $data['company_name'] ?? null,
                'company_tax_id' => $data['company_tax_id'] ?? null,
                'locale' => $data['locale'] ?? 'ro',
                'source' => $source,
                'referral_code' => $this->referralCode(),
            ]);

            return $customer;
        }

        $updates = array_filter([
            'first_name' => $customer->first_name ? null : $first,
            'last_name' => $customer->last_name ? null : $last,
            'email' => $customer->email ? null : ($email ? trim((string) $data['email']) : null),
            'email_normalized' => $customer->email_normalized ? null : $email,
            'phone' => $customer->phone ? null : ($phone ? trim((string) $data['phone']) : null),
            'phone_normalized' => $customer->phone_normalized ? null : $phone,
            'company_name' => $customer->company_name ? null : ($data['company_name'] ?? null),
            'referral_code' => $customer->referral_code ? null : $this->referralCode(),
        ]);
        if ($updates) {
            $customer->fill($updates)->save();
        }

        if ($byEmail && $byPhone && $byEmail->id !== $byPhone->id) {
            CustomerNote::query()->create([
                'customer_id' => $byEmail->id,
                'body' => "Posibil duplicat: telefonul folosit la ultima rezervare aparține și clientului #{$byPhone->id}. Verificați și uniți din CRM dacă este aceeași persoană.",
                'visibility' => 'staff',
            ]);
        }

        return $customer;
    }

    private function splitName(?string $name, ?string $first, ?string $last): array
    {
        if ($first || $last) {
            return [$first ? trim($first) : null, $last ? trim($last) : null];
        }
        $name = trim((string) $name);
        if ($name === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', $name);
        $firstName = array_shift($parts);

        return [$firstName, $parts ? implode(' ', $parts) : null];
    }

    private function referralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Customer::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
