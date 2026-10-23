<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class Post extends Model
{
    use HasTranslations;

    protected $table = 'posts';

    protected $guarded = ['id'];

    public array $translatable = ['slug', 'title', 'excerpt', 'body', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return [
            'published_locales' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'author_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')->where('published_at', '<=', now());
    }
}
