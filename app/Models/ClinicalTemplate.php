<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['name' => 'array', 'fields' => 'array', 'validated_at' => 'datetime', 'version' => 'integer'];
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->name[$locale] ?? $this->name['ro'] ?? $this->key);
    }

    public function isUsable(): bool
    {
        return $this->status === 'validated';
    }
}
