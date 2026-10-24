<?php

namespace App\Filament\Resources\AiConversations\Pages;

use App\Filament\Resources\AiConversations\AiConversationResource;
use App\Modules\Ai\Models\AiUsageDaily;
use App\Support\LocalTime;
use Filament\Resources\Pages\ListRecords;

class ListAiConversations extends ListRecords
{
    protected static string $resource = AiConversationResource::class;

    public function getSubheading(): ?string
    {
        $today = AiUsageDaily::query()->whereDate('day', LocalTime::now()->toDateString())->first();
        $month = AiUsageDaily::query()->where('day', '>=', LocalTime::now()->startOfMonth()->toDateString())->sum('cost_micro_usd');

        return sprintf('Cost estimat azi: %.2f USD (%d cereri) · luna aceasta: %.2f USD · plafon zilnic: %s USD',
            ($today?->cost_micro_usd ?? 0) / 1_000_000, $today?->requests ?? 0, $month / 1_000_000, setting('ai.daily_budget_usd', '5'));
    }
}
