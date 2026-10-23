<?php

namespace App\Modules\Finance\Models;

use App\Modules\Booking\Models\Booking;
use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Factură / proformă / factură de stornare. Odată emisă, nu se mai modifică: corecțiile se fac prin storno.
 */
class Invoice extends Model
{
    public const TYPES = [
        'invoice' => 'Factură',
        'proforma' => 'Proformă',
        'credit_note' => 'Factură de stornare',
    ];

    public const EFACTURA_STATUSES = [
        'not_applicable' => 'Nu se transmite',
        'pending' => 'De transmis',
        'generated' => 'Generat',
        'validated' => 'Validat local',
        'sent' => 'Trimis',
        'accepted' => 'Acceptat ANAF',
        'rejected' => 'Respins',
        'error' => 'Eroare',
    ];

    protected $table = 'invoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'tax_point_date' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'seller_snapshot' => 'array',
            'buyer_snapshot' => 'array',
            'vat_breakdown' => 'array',
            'prices_include_vat' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Protecție: documentele emise nu se rescriu. Sunt permise doar câmpurile operaționale.
        static::updating(function (Invoice $invoice) {
            if ($invoice->getOriginal('status') !== 'issued') {
                return;
            }
            $allowed = ['paid_minor', 'efactura_status', 'pdf_path', 'updated_at', 'status'];
            $changed = array_diff(array_keys($invoice->getDirty()), $allowed);
            if ($changed) {
                throw new \LogicException('Factura emisă nu poate fi modificată ('.implode(', ', $changed).'). Folosiți o factură de stornare.');
            }
            if ($invoice->isDirty('status') && $invoice->document_type !== 'proforma') {
                throw new \LogicException('Starea unei facturi emise nu se schimbă. Folosiți o factură de stornare.');
            }
        });
        static::deleting(function (Invoice $invoice) {
            if ($invoice->status === 'issued') {
                throw new \LogicException('Factura emisă nu poate fi ștearsă.');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(DocumentSeries::class, 'series_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_invoice_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(EfacturaSubmission::class)->latest('id');
    }

    public function currentSubmission(): HasOne
    {
        return $this->hasOne(EfacturaSubmission::class)->where('is_current', true)->latestOfMany();
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    public function balanceMinor(): int
    {
        return $this->gross_total_minor - $this->paid_minor;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->document_type] ?? $this->document_type;
    }
}
