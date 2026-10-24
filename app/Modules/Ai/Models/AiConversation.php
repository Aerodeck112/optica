<?php

namespace App\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'state' => 'encrypted:array',
            'verified_until' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class);
    }
}
