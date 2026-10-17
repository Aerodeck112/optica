<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasTranslatedContent, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'published_content' => 'array',
            'published_at' => 'datetime',
            'default_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(Offering::class);
    }

    public function name(?string $locale = null): string
    {
        return $this->contentValue('name', $locale) ?? $this->code;
    }
}
