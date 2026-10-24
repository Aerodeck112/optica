<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImport extends Model
{
    protected $table = 'contact_imports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'errors' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
