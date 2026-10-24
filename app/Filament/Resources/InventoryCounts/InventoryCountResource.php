<?php

namespace App\Filament\Resources\InventoryCounts;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\StockService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventoryCountResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = InventoryCount::class;

    protected static string $viewPermission = 'inventory.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Stocuri';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $modelLabel = 'inventar';

    protected static ?string $pluralModelLabel = 'Inventare';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false; // se pornește cu acțiunea „Inventar nou”, care preia stocul scriptic
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function startAction(): Action
    {
        return Action::make('start')->label('Inventar nou')->icon('heroicon-o-plus')
            ->schema([Select::make('warehouse_id')->label('Gestiune')->required()->native()->options(fn () => Warehouse::query()->where('is_active', true)->pluck('name', 'id'))])
            ->modalDescription('Se creează lista articolelor urmărite, cu stocul scriptic actual. Completați cantitățile numărate, apoi confirmați.')
            ->action(function (array $data, $livewire) {
                $count = app(StockService::class)->startCount(Warehouse::query()->findOrFail($data['warehouse_id']), auth()->id());
                $livewire->redirect(self::getUrl('view', ['record' => $count]));
            });
    }

    public static function confirmAction(): Action
    {
        return Action::make('confirm')->label('Confirmă inventarul')->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (InventoryCount $record) => $record->status === 'draft')
            ->requiresConfirmation()
            ->modalDescription(fn (InventoryCount $record) => 'Diferențele dintre cantitățile numărate și stocul scriptic se înregistrează ca mișcări „Inventar”. Linii numărate: '
                .$record->lines()->whereNotNull('counted_quantity')->count().' din '.$record->lines()->count().'. Liniile necompletate rămân neschimbate.')
            ->action(function (InventoryCount $record) {
                try {
                    app(StockService::class)->confirmCount($record, auth()->id());
                    Notification::make()->success()->title('Inventarul a fost confirmat.')->send();
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('warehouse')->withCount('lines'))
            ->columns([
                TextColumn::make('counted_on')->label('Data')->date('d.m.Y'),
                TextColumn::make('warehouse.name')->label('Gestiune'),
                TextColumn::make('lines_count')->label('Articole'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => $state === 'confirmed' ? 'Confirmat' : 'În lucru')->color(fn ($state) => $state === 'confirmed' ? 'success' : 'warning'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->columnSpanFull()->schema([
                TextEntry::make('warehouse.name')->label('Gestiune'),
                TextEntry::make('counted_on')->label('Data')->date('d.m.Y'),
                TextEntry::make('status')->label('Stare')->formatStateUsing(fn ($state) => $state === 'confirmed' ? 'Confirmat' : 'În lucru — completați cantitățile numărate în tabelul de mai jos'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryCounts::route('/'),
            'view' => Pages\ViewInventoryCount::route('/{record}'),
        ];
    }
}
