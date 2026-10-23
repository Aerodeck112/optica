<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class EfacturaInboxMessage extends Model
{
    protected $table = 'efactura_inbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'parsed' => 'array',
            'anaf_created_at' => 'immutable_datetime',
        ];
    }
}
