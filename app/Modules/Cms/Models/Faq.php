<?php

namespace App\Modules\Cms\Models;

use App\Modules\Core\Concerns\PublishedInLocales;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Faq extends Model
{
    use HasTranslations, PublishedInLocales;

    protected $table = 'faqs';

    protected $guarded = ['id'];

    public array $translatable = ['question', 'answer'];

    protected function casts(): array
    {
        return [
            'published_locales' => 'array',
            'is_published' => 'boolean',
            'use_in_ai' => 'boolean',
        ];
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
