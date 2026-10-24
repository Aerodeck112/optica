<?php

namespace App\Filament\Resources\AiConversations;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Modules\Ai\Models\AiConversation;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Conversațiile asistentului, cu e-mailurile și telefoanele mascate; păstrate conform „ai.retention_days”. */
class AiConversationResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = AiConversation::class;

    protected static string $viewPermission = 'ai.manage';

    protected static ?string $managePermission = null;

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Asistent AI';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

    protected static ?string $modelLabel = 'conversație';

    protected static ?string $pluralModelLabel = 'Conversații';

    public const STATUSES = ['open' => 'Deschisă', 'handed_off' => 'Predată echipei', 'closed' => 'Închisă', 'blocked' => 'Blocată'];

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(4)->schema([
                TextEntry::make('created_at')->label('Începută')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextEntry::make('status')->label('Stare')->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state),
                TextEntry::make('message_count')->label('Mesaje'),
                TextEntry::make('tokens')->label('Tokeni (intrare / ieșire)')->state(fn (AiConversation $record) => number_format($record->input_tokens, 0, ',', '.').' / '.number_format($record->output_tokens, 0, ',', '.')),
                TextEntry::make('booking_id')->label('Rezervare creată')->formatStateUsing(fn () => 'Deschide')->url(fn (AiConversation $record) => $record->booking_id ? BookingResource::getUrl('view', ['record' => $record->booking_id]) : null)->placeholder('—'),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextColumn::make('first')->label('Prima întrebare')->state(fn (AiConversation $record) => $record->messages()->where('role', 'user')->orderBy('id')->value('content_redacted'))->limit(70),
                TextColumn::make('message_count')->label('Mesaje'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state),
                TextColumn::make('result')->label('Rezultat')->state(fn (AiConversation $record) => $record->booking_id ? 'Rezervare' : ($record->lead_id ? 'Cerere ofertă' : null))->placeholder('—')->badge()->color('success'),
            ])
            ->filters([SelectFilter::make('status')->label('Stare')->options(self::STATUSES)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\MessagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiConversations::route('/'),
            'view' => Pages\ViewAiConversation::route('/{record}'),
        ];
    }
}
