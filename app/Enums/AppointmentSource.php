<?php

namespace App\Enums;

enum AppointmentSource: string
{
    case Online = 'online';
    case Reception = 'reception';
    case Phone = 'phone';
    case Crm = 'crm';
    case Import = 'import';

    public function isOnline(): bool
    {
        return $this === self::Online;
    }
}
