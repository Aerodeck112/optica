<?php

namespace App\Modules\Booking\Services;

use App\Modules\Catalog\Models\Package;
use Carbon\CarbonImmutable;

/**
 * Datele unei rezervări noi, validate de canalul care le trimite (formular, AI, recepție).
 */
final class BookingData
{
    /**
     * @param  array<string,int>  $ageGroups  categorii agregate, fără date individuale despre copii
     * @param  array<int,int>  $addons  [addon_id => cantitate]
     * @param  array<int,string>  $acceptedPolicyTypes  tipurile de documente acceptate explicit
     * @param  array{email?:bool,sms?:bool}  $marketingConsent  opțiuni separate, nebifate implicit
     */
    public function __construct(
        public readonly Package $package,
        public readonly ?CarbonImmutable $startsAt,
        public readonly int $participants,
        public readonly string $contactName,
        public readonly ?string $contactEmail,
        public readonly ?string $contactPhone,
        public readonly string $source = 'web',
        public readonly array $ageGroups = [],
        public readonly array $addons = [],
        public readonly ?string $companyName = null,
        public readonly ?string $customerNotes = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $holdPublicId = null,
        public readonly ?string $holdToken = null,
        public readonly array $acceptedPolicyTypes = [],
        public readonly array $marketingConsent = [],
        public readonly array $consentContext = [],
        public readonly string $locale = 'ro',
        public readonly ?int $gameId = null,
        public readonly ?int $createdBy = null,
        public readonly bool $forceRequest = false,
        public readonly ?string $staffStatus = null,   // pentru recepție/admin: confirmed | pending
        public readonly ?int $resourceId = null,       // recepția poate alege resursa
        public readonly ?int $leadId = null,
        public readonly ?string $internalNotes = null,
        public readonly ?string $analyticsClientRef = null,
    ) {}

    public function isStaff(): bool
    {
        return in_array($this->source, ['reception', 'admin', 'phone', 'lead'], true);
    }
}
