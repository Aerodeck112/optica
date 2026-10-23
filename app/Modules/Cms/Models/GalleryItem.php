<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class GalleryItem extends Model
{
    use HasTranslations;

    protected $table = 'gallery_items';

    protected $guarded = ['id'];

    public array $translatable = ['caption'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
