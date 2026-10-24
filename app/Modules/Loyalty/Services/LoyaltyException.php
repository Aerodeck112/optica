<?php

namespace App\Modules\Loyalty\Services;

use RuntimeException;

class LoyaltyException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
