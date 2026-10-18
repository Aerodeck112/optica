<?php

namespace App\Support\Consent;

use App\Models\Consent;
use App\Models\Patient;
use Illuminate\Support\Collection;

/**
 * Stores notices, agreements and marketing preferences separately, with text version and source.
 * Marketing is never a condition for booking (cap. 6) and defaults to "no".
 */
class ConsentRecorder
{
    public function version(string $purpose): string
    {
        return (string) config('optica.consents.versions.'.$purpose, '1');
    }

    /**
     * @param  array<string, bool>  $marketing  channel => granted
     */
    public function recordBooking(array $contact, string $locale, bool $privacyAcknowledged, array $marketing, ?int $appointmentId, string $source = 'online_booking', ?int $leadId = null): Collection
    {
        $base = [
            'appointment_id' => $appointmentId,
            'lead_id' => $leadId,
            'patient_id' => $contact['patient_id'] ?? null,
            'contact_phone' => Patient::normalizePhone($contact['phone'] ?? null),
            'contact_email' => isset($contact['email']) && $contact['email'] ? mb_strtolower($contact['email']) : null,
            'locale' => $locale,
            'source' => $source,
            'ip_address' => request()?->ip(),
            'given_at' => now(),
            'recorded_by' => auth()->id(),
        ];

        $rows = collect();
        $rows->push(Consent::create($base + ['purpose' => 'privacy_notice', 'channel' => null, 'granted' => $privacyAcknowledged, 'text_version' => $this->version('privacy_notice')]));
        foreach (Consent::CHANNELS as $channel) {
            if (array_key_exists($channel, $marketing)) {
                $rows->push(Consent::create($base + ['purpose' => 'marketing', 'channel' => $channel, 'granted' => (bool) $marketing[$channel], 'text_version' => $this->version('marketing')]));
            }
        }

        return $rows;
    }

    /** Latest marketing decision for a channel wins; no record means no consent (MKT-02). */
    public function allowsMarketing(?int $patientId, ?string $phone, ?string $email, string $channel): bool
    {
        $phone = Patient::normalizePhone($phone);
        $latest = Consent::query()
            ->where('purpose', 'marketing')
            ->where('channel', $channel)
            ->where(function ($q) use ($patientId, $phone, $email) {
                if ($patientId) {
                    $q->orWhere('patient_id', $patientId);
                }
                if ($phone) {
                    $q->orWhere('contact_phone', $phone);
                }
                if ($email) {
                    $q->orWhere('contact_email', mb_strtolower($email));
                }
            })
            ->latest('given_at')->latest('id')
            ->first();

        return $latest !== null && $latest->granted && $latest->withdrawn_at === null;
    }

    public function withdrawMarketing(?int $patientId, ?string $phone, ?string $email, string $channel, string $source, string $locale = 'ro'): Consent
    {
        return Consent::create([
            'patient_id' => $patientId,
            'contact_phone' => Patient::normalizePhone($phone),
            'contact_email' => $email ? mb_strtolower($email) : null,
            'purpose' => 'marketing',
            'channel' => $channel,
            'granted' => false,
            'text_version' => $this->version('marketing'),
            'locale' => $locale,
            'source' => $source,
            'ip_address' => request()?->ip(),
            'given_at' => now(),
            'recorded_by' => auth()->id(),
        ]);
    }
}
