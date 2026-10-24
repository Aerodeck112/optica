<?php

namespace App\Filament\Resources\AiConversations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Mesaje (date de contact mascate)';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id')->paginated([50])->columns([
            TextColumn::make('created_at')->label('Ora')->dateTime('H:i:s', config('aw.timezone')),
            TextColumn::make('role')->label('Cine')->badge()->formatStateUsing(fn ($state) => ['user' => 'Vizitator', 'assistant' => 'Asistent', 'tool' => 'Tool'][$state] ?? $state),
            TextColumn::make('content_redacted')->label('Mesaj')->wrap()->placeholder('—')
                ->description(fn ($record) => $record->tool_name ? $record->tool_name.' '.json_encode($record->tool_summary, JSON_UNESCAPED_UNICODE) : null),
        ]);
    }
}
