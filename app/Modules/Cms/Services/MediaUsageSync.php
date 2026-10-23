<?php

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\MediaUsage;
use Illuminate\Database\Eloquent\Model;

/**
 * Ține evidența locurilor în care este folosit fiecare fișier media,
 * pentru verificarea „este folosit?” înainte de ștergere.
 */
class MediaUsageSync
{
    /** @param array<string, array<int, int|string|null>> $byField câmp => id-uri media */
    public function sync(Model $model, array $byField): void
    {
        $rows = [];
        foreach ($byField as $field => $ids) {
            foreach (array_unique(array_filter(array_map(fn ($id) => is_numeric($id) ? (int) $id : null, $ids))) as $id) {
                $rows[] = ['media_id' => $id, 'field' => $field];
            }
        }

        MediaUsage::query()->where('usable_type', $model->getMorphClass())->where('usable_id', $model->getKey())->delete();
        foreach ($rows as $row) {
            MediaUsage::query()->create($row + ['usable_type' => $model->getMorphClass(), 'usable_id' => $model->getKey()]);
        }
    }

    public function forget(Model $model): void
    {
        MediaUsage::query()->where('usable_type', $model->getMorphClass())->where('usable_id', $model->getKey())->delete();
    }
}
