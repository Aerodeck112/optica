<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounterSaleLine extends Model
{
    protected $table = 'counter_sale_lines';

    protected $guarded = ['id'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(CounterSale::class, 'counter_sale_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
