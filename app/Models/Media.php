<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $table = 'media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variants' => 'array', 'alt' => 'array'];
    }

    public function url(?int $width = null): string
    {
        $path = $this->path;
        if ($width && $this->variants) {
            foreach ($this->variants as $w => $variant) {
                if ((int) $w >= $width) {
                    $path = $variant;
                    break;
                }
            }
        }

        return Storage::disk('public')->url($path);
    }

    public function srcset(): string
    {
        return collect($this->variants ?? [])->map(fn ($p, $w) => Storage::disk('public')->url($p).' '.$w.'w')->join(', ');
    }

    public function altText(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->alt[$locale] ?? $this->alt['ro'] ?? '');
    }
}
