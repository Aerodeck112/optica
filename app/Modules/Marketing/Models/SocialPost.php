<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Cms\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O postare planificată pe rețelele sociale. Aplicația nu publică singură: echipa descarcă imaginile,
 * copiază textul, publică din aplicația rețelei și marchează postarea „Publicată”.
 */
class SocialPost extends Model
{
    public const CHANNELS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'story' => 'Story (Instagram/Facebook)', 'tiktok' => 'TikTok'];

    public const FORMATS = ['post' => 'Postare (o imagine)', 'carousel' => 'Carusel (mai multe imagini)', 'story' => 'Story', 'reel' => 'Reel / video scurt'];

    public const STATUSES = ['planned' => 'Planificată', 'ready' => 'Pregătită', 'published' => 'Publicată', 'skipped' => 'Sărită'];

    public const THEMES = [
        'opening' => 'Deschidere',
        'game' => 'Jocul săptămânii',
        'friends' => 'Prieteni și grupuri',
        'how' => 'Cum funcționează',
        'birthday' => 'Aniversări',
        'teens' => 'Adolescenți',
        'family' => 'Familie',
        'corporate' => 'Companii și team building',
        'behind' => 'Din culise / echipament',
        'weekend' => 'Weekend',
        'vouchers' => 'Vouchere cadou',
        'community' => 'Comunitate',
    ];

    protected $table = 'social_posts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'publish_on' => 'immutable_date',
            'channels' => 'array',
            'media_ids' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return Collection<int, Media> imaginile în ordinea aleasă */
    public function media(): Collection
    {
        $ids = array_values(array_map('intval', (array) $this->media_ids));
        if (! $ids) {
            return new Collection;
        }
        $items = Media::query()->whereIn('id', $ids)->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn ($id) => $items[$id] ?? null, $ids))));
    }

    /** Textul complet de copiat: descrierea urmată de hashtag-uri. */
    public function fullText(): string
    {
        return trim($this->caption.($this->hashtags ? "\n\n".$this->hashtags : ''));
    }
}
