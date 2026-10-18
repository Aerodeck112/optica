<?php

namespace App\Domain\Orders;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/** An order is ready for pickup; listeners notify the patient in their language (CMD-02). */
class OrderReady
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
