<?php

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\Media;
use Illuminate\Support\Collection;

/**
 * Încărcare în lot a fișierelor media folosite de blocuri (evită interogări repetate per imagine).
 */
class MediaRepository
{
    /** @var array<int, Media|null> */
    private array $byId = [];

    /** @var array<string, Media|null> */
    private array $byKey = [];

    public function preload(iterable $ids): void
    {
        $missing = collect($ids)->filter()->map(fn ($id) => (int) $id)->reject(fn ($id) => array_key_exists($id, $this->byId))->unique()->values();
        if ($missing->isEmpty()) {
            return;
        }
        $found = Media::query()->whereIn('id', $missing)->get()->keyBy('id');
        foreach ($missing as $id) {
            $this->byId[$id] = $found[$id] ?? null;
        }
    }

    public function find(int|string|null $id): ?Media
    {
        if (! $id) {
            return null;
        }
        if (! is_numeric($id)) {
            return $this->byStaticKey((string) $id);
        }
        $id = (int) $id;
        if (! array_key_exists($id, $this->byId)) {
            $this->byId[$id] = Media::query()->find($id);
        }
        $media = $this->byId[$id];

        return $media && $media->isPublishable() ? $media : null;
    }

    public function byStaticKey(string $key): ?Media
    {
        if (! array_key_exists($key, $this->byKey)) {
            $this->byKey[$key] = Media::query()->where('static_key', $key)->first();
        }

        return $this->byKey[$key];
    }

    /** @return Collection<int, Media> */
    public function many(array $ids): Collection
    {
        $this->preload($ids);

        return collect($ids)->map(fn ($id) => $this->find($id))->filter()->values();
    }

    /** Colectează recursiv id-urile media dintr-o structură de blocuri (chei *_id, *_ids, image, images). */
    public static function collectIds(array $blocks): array
    {
        $ids = [];
        array_walk_recursive($blocks, function ($value, $key) use (&$ids) {
            if (is_string($key) && (str_ends_with($key, 'image') || str_ends_with($key, '_id') || str_ends_with($key, 'media')) && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        });
        foreach ($blocks as $block) {
            foreach (['images', 'media_ids'] as $listKey) {
                foreach ((array) ($block['data'][$listKey] ?? []) as $id) {
                    if (is_numeric($id)) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
