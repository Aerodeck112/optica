<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

/**
 * Fișier din biblioteca media. Imaginile au variante optimizate (AVIF/WebP/JPEG) pe mai multe lățimi.
 * Proveniența și drepturile sunt obligatorii pentru a nu publica materiale fără drept de utilizare.
 */
class Media extends Model
{
    use HasTranslations, SoftDeletes;

    public const CATEGORIES = [
        'network_photo' => 'Fotografie din rețeaua Another World (altă locație)',
        'local_photo' => 'Fotografie din arena din Cluj',
        'game_art' => 'Imagine din joc / randare',
        'brand_art' => 'Artă de brand (randare)',
        'document' => 'Document',
        'other' => 'Altele',
    ];

    public const RIGHTS = [
        'owned' => 'Proprietate Another World Cluj',
        'licensed_franchise' => 'Licență franciză Another World',
        'licensed_other' => 'Licență terț (vezi note)',
        'unknown' => 'Necunoscut — nu se publică',
    ];

    protected $table = 'media';

    protected $guarded = ['id'];

    public array $translatable = ['alt', 'caption'];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'depicts_minors' => 'boolean',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(self::class, 'poster_id');
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }

    public function isPublishable(): bool
    {
        return $this->rights_status !== 'unknown';
    }

    public function originalUrl(): string
    {
        if ($this->static_key) {
            return '/'.ltrim($this->directory.'/'.$this->filename, '/');
        }

        return Storage::disk($this->disk)->url($this->directory.'/'.$this->filename);
    }

    /** @return array<string, array<int, array{width:int,height:int,url:string}>> grupate pe format */
    public function variantSets(): array
    {
        $sets = [];
        foreach ((array) $this->variants as $variant) {
            $url = $this->static_key
                ? '/'.ltrim($variant['path'], '/')
                : Storage::disk($this->disk)->url($variant['path']);
            $sets[$variant['format']][] = ['width' => (int) $variant['width'], 'height' => (int) $variant['height'], 'url' => $url];
        }
        foreach ($sets as &$set) {
            usort($set, fn ($a, $b) => $a['width'] <=> $b['width']);
        }

        return $sets;
    }

    /** Cea mai potrivită variantă (JPEG/WebP) pentru o lățime — folosită pentru Open Graph și e-mailuri. */
    public function urlForWidth(int $width = 1200, array $formats = ['jpg', 'webp']): string
    {
        $sets = $this->variantSets();
        foreach ($formats as $format) {
            if (! empty($sets[$format])) {
                $candidates = $sets[$format];
                foreach ($candidates as $candidate) {
                    if ($candidate['width'] >= $width) {
                        return $candidate['url'];
                    }
                }

                return end($candidates)['url'];
            }
        }

        return $this->originalUrl();
    }

    public function altText(?string $locale = null): string
    {
        return (string) ($this->getTranslation('alt', $locale ?? app()->getLocale(), true) ?: '');
    }

    public function aspectRatio(): ?float
    {
        return $this->width && $this->height ? $this->width / $this->height : null;
    }

    /** Folosită de verificarea „poate fi șters?”: lista locurilor unde apare fișierul. */
    public function usageSummary(): array
    {
        return $this->usages()->get()->map(fn (MediaUsage $usage) => [
            'type' => class_basename($usage->usable_type),
            'id' => $usage->usable_id,
            'field' => $usage->field,
        ])->all();
    }
}
