<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    public const KINDS = ['frame', 'lens', 'treatment', 'contact_lens', 'service', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price_cents' => 'integer', 'quantity' => 'integer'];
    }

    public function totalCents(): int
    {
        return $this->unit_price_cents * $this->quantity;
    }
}
