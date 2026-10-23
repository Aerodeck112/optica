<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Document juridic versionat. Versiunea publicată nu se rescrie: o modificare creează o versiune nouă,
 * iar acceptările din rezervări păstrează tipul, versiunea și hash-ul conținutului.
 */
class LegalDocument extends Model
{
    use HasTranslations;

    public const TYPES = [
        'terms' => 'Termeni și condiții',
        'privacy' => 'Politica de confidențialitate',
        'cookies' => 'Politica de cookies',
        'booking_policy' => 'Politica de rezervare, anulare, reprogramare și rambursare',
        'voucher_terms' => 'Condiții pentru vouchere',
        'arena_rules' => 'Regulamentul arenei',
        'minors' => 'Reguli pentru participarea minorilor',
        'safety' => 'Informare de siguranță',
        'media_consent' => 'Acord pentru fotografii și video',
    ];

    protected $table = 'legal_documents';

    protected $guarded = ['id'];

    public array $translatable = ['title', 'body', 'slug'];

    protected function casts(): array
    {
        return [
            'review_flags' => 'array',
            'effective_at' => 'immutable_datetime',
        ];
    }

    public static function current(string $type): ?self
    {
        return static::query()
            ->where('type', $type)
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    public function computeHash(): string
    {
        return hash('sha256', json_encode([$this->type, $this->version, $this->getTranslations('body')], JSON_UNESCAPED_UNICODE));
    }

    public function acceptanceRecord(): array
    {
        return [
            'type' => $this->type,
            'version' => $this->version,
            'hash' => $this->content_hash ?: $this->computeHash(),
            'accepted_at' => now()->toIso8601String(),
        ];
    }

    public function hasOpenReviewFlags(): bool
    {
        return collect($this->review_flags ?? [])->contains(fn ($flag) => ! ($flag['resolved'] ?? false));
    }
}
