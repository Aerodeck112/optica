<?php

namespace App\Modules\Crm\Models;

use App\Modules\Booking\Models\Booking;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Models\LoyaltyTier;
use App\Modules\Loyalty\Models\Voucher;
use App\Modules\Notifications\Models\MessageLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Persoana adultă care rezervă (sau compania). Autentificarea în contul de client se face prin link unic pe e-mail,
 * fără parolă stocată.
 */
class Customer extends Authenticatable
{
    use HasFactory;

    protected $table = 'customers';

    protected $guarded = ['id'];

    /** Valorile implicite din baza de date, disponibile și pe modelele abia create. */
    protected $attributes = ['type' => 'person', 'locale' => 'ro', 'billing_country' => 'RO', 'bookings_count' => 0, 'visits_count' => 0, 'paid_total_minor' => 0];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'interests' => 'array',
            'first_booking_at' => 'immutable_datetime',
            'last_booking_at' => 'immutable_datetime',
            'last_visit_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory()
    {
        return \Database\Factories\CustomerFactory::new();
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function fullName(): string
    {
        if ($this->type === 'company' && $this->company_name) {
            return $this->company_name;
        }

        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: ($this->email ?? 'Client #'.$this->id);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest();
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function consentEvents(): HasMany
    {
        return $this->hasMany(ConsentEvent::class)->latest('created_at');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessageLog::class)->latest();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function loyaltyEntries(): HasMany
    {
        return $this->hasMany(LoyaltyLedgerEntry::class)->latest('created_at');
    }

    public function vouchersBought(): HasMany
    {
        return $this->hasMany(Voucher::class, 'buyer_customer_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'loyalty_tier_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function hasMarketingConsent(string $channel): bool
    {
        return $this->consents()
            ->where('channel', $channel)
            ->where('purpose', 'marketing')
            ->where('status', 'granted')
            ->exists();
    }

    public function loyaltyBalance(): int
    {
        return (int) $this->loyaltyEntries()->sum('delta');
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }
}
