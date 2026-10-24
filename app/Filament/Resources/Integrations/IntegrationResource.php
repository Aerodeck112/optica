<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Core\Models\Integration;
use App\Modules\Integrations\IntegrationCatalog;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Integration::class;

    protected static string $viewPermission = 'integrations.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $modelLabel = 'integrare';

    protected static ?string $pluralModelLabel = 'Integrări și date de acces';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'production_verified', 'configured' => 'success',
            'sandbox_tested' => 'info',
            'adapter_ready' => 'warning',
            'blocked' => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('key')->label('Integrare')->formatStateUsing(fn ($state) => IntegrationCatalog::get($state)['label'] ?? $state)
                    ->description(fn (Integration $record) => IntegrationCatalog::get($record->key)['drivers'][$record->driver]['label'] ?? 'Furnizor neales'),
                TextColumn::make('mode')->label('Mod')->formatStateUsing(fn ($state) => IntegrationCatalog::MODES[$state] ?? $state)->badge()->color(fn ($state) => $state === 'live' ? 'success' : 'gray'),
                TextColumn::make('status')->label('Stare')->badge()->wrap()->formatStateUsing(fn ($state) => IntegrationCatalog::STATUSES[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                TextColumn::make('last_tested_at')->label('Ultimul test')->since()->placeholder('Niciodată')->description(fn (Integration $record) => $record->last_test_result ? str($record->last_test_result)->limit(70) : null),
            ])
            ->recordActions([EditAction::make()->label('Configurează')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntegrations::route('/'),
            'edit' => Pages\EditIntegration::route('/{record}/edit'),
        ];
    }
}
