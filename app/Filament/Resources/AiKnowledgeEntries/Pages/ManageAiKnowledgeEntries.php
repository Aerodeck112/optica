<?php

namespace App\Filament\Resources\AiKnowledgeEntries\Pages;

use App\Filament\Resources\AiKnowledgeEntries\AiKnowledgeEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAiKnowledgeEntries extends ManageRecords
{
    protected static string $resource = AiKnowledgeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['status' => 'draft'])];
    }
}
