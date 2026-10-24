<?php

namespace App\Filament\Resources\ArenaResources;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Booking\Models\Resource as ArenaResource;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Resursele rezervabile (arena, zone). O resursă „părinte” blochează și resursele „copil” (și invers):
 * de exemplu, închirierea întregii arene ocupă toate zonele ei.
 */
class ArenaResourceResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = ArenaResource::class;

    protected static string $viewPermission = 'resources.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Configurare';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $modelLabel = 'resursă';

    protected static ?string $pluralModelLabel = 'Resurse (arene / zone)';

    protected static ?string $slug = 'arena-resources';

    public const TYPES = ['arena' => 'Arenă VR', 'zone' => 'Zonă / parte de arenă', 'room' => 'Sală', 'equipment' => 'Echipament'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label('Nume')->required()->maxLength(120),
                Select::make('type')->label('Tip')->options(self::TYPES)->required()->default('arena'),
                Select::make('parent_id')->label('Face parte din')->options(fn (?ArenaResource $record) => ArenaResource::query()->when($record, fn ($q) => $q->whereKeyNot($record->id))->pluck('name', 'id'))->placeholder('—'),
                TextInput::make('capacity')->label('Capacitate maximă (jucători simultan)')->numeric()->minValue(1)->maxValue(500)->placeholder('De completat')
                    ->helperText('Fără capacitate configurată, sesiunile comune nu pot fi rezervate instant.'),
                Toggle::make('is_active')->label('Activă')->default(true),
                Toggle::make('is_public')->label('Rezervabilă online')->default(true)->helperText('Dezactivat = doar recepția poate rezerva pe această resursă.'),
                ColorPicker::make('color')->label('Culoare în calendar'),
                TextInput::make('sort')->label('Ordine')->numeric()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('Nume'),
                TextColumn::make('type')->label('Tip')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
                TextColumn::make('parent.name')->label('Face parte din')->placeholder('—'),
                TextColumn::make('capacity')->label('Capacitate')->placeholder('De completat'),
                IconColumn::make('is_active')->label('Activă')->boolean(),
                IconColumn::make('is_public')->label('Online')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageArenaResources::route('/')];
    }
}
