<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** O vânzare la recepție (băuturi, gustări, suveniruri din stoc), cu încasarea și bonul ei fiscal. */
class CounterSale extends Model
{
    protected $table = 'counter_sales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cancelled_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CounterSaleLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
