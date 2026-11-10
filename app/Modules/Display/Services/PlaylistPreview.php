<?php

namespace App\Modules\Display\Services;

use App\Modules\Display\Models\DisplayAsset;
use App\Modules\Display\Models\DisplayPlaylist;
use App\Modules\Display\Models\DisplayPlaylistItem;
use App\Support\LocalTime;

/**
 * Previzualizarea rotației: aceeași logică ca playerul (conținut principal cu durată minimă, materiale după pondere,
 * fără repetare consecutivă, limită de reclame la rând). Fiind aleatorie, ordinea reală poate diferi.
 */
class PlaylistPreview
{
    /** @return array<int, array{at:string, label:string, seconds:int}> */
    public function simulate(DisplayPlaylist $playlist, string $screenType, int $minutes = 10, int $seed = 7): array
    {
        mt_srand($seed);
        $local = LocalTime::now();
        $items = $playlist->items()->get()->filter(fn ($i) => $i->isScheduledAt($local))->values();
        $main = $items->first(fn ($i) => $i->type === 'main' || ($screenType === 'hall_of_legends' && $i->type === 'leaderboard'));
        $mainSeconds = max((int) ($main?->duration_seconds ?? 50), (int) $playlist->min_main_seconds);
        $mainLabel = $screenType === 'live_arena' ? 'Sesiunea live (sau „Următoarea aventură”)' : 'Clasament general';
        $fillers = $items->reject(fn ($i) => $main && $i->id === $main->id)->values();
        $assets = DisplayAsset::query()->where('is_active', true)->get()->filter(fn ($a) => $a->appliesTo($screenType));

        $out = [];
        $t = 0;
        $lastWasMain = false;
        $inRow = 0;
        $lastId = null;
        $cursor = -1;
        while ($t < $minutes * 60 && count($out) < 80) {
            $pick = null;
            if ($playlist->mode === 'sequential') {
                $cursor = ($cursor + 1) % max(1, $items->count());
                $pick = $items[$cursor] ?? null;
                $pick = $pick && $main && $pick->id === $main->id ? null : $pick;
            } elseif ($out && ($lastWasMain || ($inRow < $playlist->max_consecutive_ads && mt_rand(1, 100) <= 40)) && $fillers->isNotEmpty()) {
                $candidates = $fillers->count() > 1 ? $fillers->reject(fn ($i) => $i->id === $lastId)->values() : $fillers;
                $pick = $this->weighted($candidates->all());
            }
            if ($pick) {
                $label = DisplayPlaylistItem::TYPES[$pick->type] ?? $pick->type;
                if (in_array($pick->type, ['promo', 'video'], true)) {
                    $pool = $assets->where('kind', $pick->type === 'video' ? 'video' : 'image');
                    $asset = $pick->display_asset_id ? $pool->firstWhere('id', $pick->display_asset_id) : ($pool->isNotEmpty() ? $this->weighted($pool->values()->all()) : null);
                    $label .= $asset ? ': '.$asset->title : ' (lipsă material — se sare)';
                }
                $out[] = ['at' => gmdate('i:s', $t), 'label' => $label, 'seconds' => (int) $pick->duration_seconds];
                $t += (int) $pick->duration_seconds;
                $lastId = $pick->id;
                $inRow = $lastWasMain ? 1 : $inRow + 1;
                $lastWasMain = false;
            } else {
                $out[] = ['at' => gmdate('i:s', $t), 'label' => $mainLabel, 'seconds' => $mainSeconds];
                $t += $mainSeconds;
                $lastWasMain = true;
                $inRow = 0;
            }
        }

        return $out;
    }

    private function weighted(array $list): mixed
    {
        $total = array_sum(array_map(fn ($x) => max(1, (int) $x->weight), $list));
        $r = mt_rand(1, max(1, $total));
        foreach ($list as $x) {
            $r -= max(1, (int) $x->weight);
            if ($r <= 0) {
                return $x;
            }
        }

        return end($list);
    }
}
