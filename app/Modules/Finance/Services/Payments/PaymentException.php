<?php

namespace App\Modules\Finance\Services\Payments;

class PaymentException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
