<?php

namespace App\Modules\Booking\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\Package;
use App\Modules\Crm\Models\Customer;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Notifications\Models\ScheduledNotification;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Booking extends Model
{
    public const STATUSES = [
        'request' => 'Cerere',
        'pending' => 'În așteptare',
        'confirmed' => 'Confirmată',
        'checked_in' => 'Check-in',
        'completed' => 'Finalizată',
        'cancelled' => 'Anulată',
        'no_show' => 'Neprezentare',
        'expired' => 'Expirată',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Neachitată',
        'deposit_paid' => 'Avans achitat',
        'partially_paid' => 'Achitată parțial',
        'paid' => 'Achitată',
        'partially_refunded' => 'Rambursată parțial',
        'refunded' => 'Rambursată integral',
    ];

    public const SOURCES = [
        'web' => 'Site',
        'ai' => 'Asistent virtual',
        'reception' => 'Recepție',
        'admin' => 'Administrare',
        'phone' => 'Telefon',
        'lead' => 'Din cerere eveniment',
    ];

    /** Stările care ocupă arena. O cerere (request) nu ocupă nimic. */
    public const OCCUPYING = ['pending', 'confirmed', 'checked_in', 'completed'];

    /** Stările din care rezervarea mai poate fi modificată. */
    public const OPEN = ['request', 'pending', 'confirmed'];

    public const TRANSITIONS = [
        'request' => ['pending', 'confirmed', 'cancelled', 'expired'],
        'pending' => ['confirmed', 'cancelled', 'expired'],
        'confirmed' => ['checked_in', 'cancelled', 'no_show', 'completed'],
        'checked_in' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
        'expired' => [],
    ];

    protected $table = 'bookings';

    protected $guarded = ['id'];

    /** Valorile implicite din baza de date, disponibile și pe modelele abia create. */
    protected $attributes = ['status' => 'request', 'payment_status' => 'unpaid', 'invoice_status' => 'not_invoiced', 'source' => 'web', 'currency' => 'RON', 'discount_minor' => 0, 'deposit_minor' => 0, 'paid_minor' => 0, 'refunded_minor' => 0, 'participants' => 1, 'reschedule_count' => 0, 'locale' => 'ro'];

    protected $hidden = ['manage_token_hash', 'manage_token_encrypted', 'checkin_token_hash'];

    /** Linkul de gestionare (pentru e-mailuri). Null dacă tokenul nu poate fi recuperat. */
    public function manageUrl(): ?string
    {
        if (! $this->manage_token_encrypted) {
            return null;
        }
        try {
            $token = Crypt::decryptString($this->manage_token_encrypted);
        } catch (\Throwable) {
            return null;
        }

        return lroute('booking.manage', ['token' => $token], $this->locale ?: 'ro');
    }

    public function rotateManageToken(): string
    {
        $token = Str::random(48);
        $this->forceFill([
            'manage_token_hash' => hash('sha256', $token),
            'manage_token_encrypted' => Crypt::encryptString($token),
        ])->save();

        return $token;
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'age_groups' => 'array',
            'price_snapshot' => 'array',
            'accepted_policies' => 'array',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(BookingResource::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->latest('created_at')->latest('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scheduledNotifications(): HasMany
    {
        return $this->hasMany(ScheduledNotification::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'confirmed', 'checked_in'])->where('starts_at', '>=', now()->subHours(3))->orderBy('starts_at');
    }

    public function scopeOnLocalDate(Builder $query, string $date): Builder
    {
        $start = LocalTime::fromLocal($date, '00:00') ?? now();
        $end = $start->addDay();

        return $query->where('starts_at', '>=', $start)->where('starts_at', '<', $end);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function occupiesArena(): bool
    {
        return in_array($this->status, self::OCCUPYING, true);
    }

    public function balanceMinor(): ?int
    {
        return $this->total_minor === null ? null : max(0, $this->total_minor - $this->discount_minor - $this->paid_minor + $this->refunded_minor);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status] ?? $this->payment_status;
    }

    public function localStart(): ?string
    {
        return $this->starts_at ? LocalTime::format($this->starts_at, 'l, j F Y, H:i') : null;
    }
}
