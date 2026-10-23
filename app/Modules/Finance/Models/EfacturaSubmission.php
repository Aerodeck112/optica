<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EfacturaSubmission extends Model
{
    protected $table = 'efactura_submissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'validation_errors' => 'array',
            'anaf_errors' => 'array',
            'uploaded_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'is_current' => 'boolean',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
