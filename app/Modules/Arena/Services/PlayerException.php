<?php

namespace App\Modules\Arena\Services;

use RuntimeException;

/** Eroare de business pentru jucători și sesiuni, cu mesaj clar pentru recepție. */
class PlayerException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'nickname')
    {
        parent::__construct($message);
    }
}
