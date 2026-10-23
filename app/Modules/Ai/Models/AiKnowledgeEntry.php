<?php

namespace App\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class AiKnowledgeEntry extends Model
{
    use HasTranslations;

    protected $table = 'ai_knowledge_entries';

    protected $guarded = ['id'];

    public array $translatable = ['content'];

    protected function casts(): array
    {
        return [
            'approved_at' => 'immutable_datetime',
        ];
    }
}
