<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppUpdate extends Model
{
    protected $table = 'app_updates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['log' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
