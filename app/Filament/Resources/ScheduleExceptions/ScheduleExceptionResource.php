<?php

namespace App\Filament\Resources\ScheduleExceptions;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Core\Models\ScheduleException;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ScheduleExceptionResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = ScheduleException::class;

    protected static string $viewPermission = 'resources.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Configurare';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-date-range';

    protected static ?string $modelLabel = 'excepție de program';

    protected static ?string $pluralModelLabel = 'Zile speciale (sărbători, program special)';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                DatePicker::make('date')->label('Data')->required()->unique(ignoreRecord: true)->native(false)->displayFormat('d.m.Y'),
                Toggle::make('is_closed')->label('Închis toată ziua')->live()->inline(false),
                TimePicker::make('opens_at')->label('Deschidere')->seconds(false)->hidden(fn (Get $get) => $get('is_closed'))->required(fn (Get $get) => ! $get('is_closed')),
                TimePicker::make('closes_at')->label('Închidere')->seconds(false)->hidden(fn (Get $get) => $get('is_closed'))->required(fn (Get $get) => ! $get('is_closed')),
                TextInput::make('reason')->label('Motiv (intern)')->maxLength(255)->columnSpanFull(),
            ]),
            Fields::localeTabs(fn (string $l) => [TextInput::make("public_note.{$l}")->label('Mesaj afișat clienților (opțional)')->maxLength(200)], 'exception'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn ($query) => $query)
            ->columns([
                TextColumn::make('date')->label('Data')->date('D, d.m.Y'),
                IconColumn::make('is_closed')->label('Închis')->boolean(),
                TextColumn::make('hours')->label('Program')->state(fn (ScheduleException $record) => $record->is_closed ? '—' : substr((string) $record->opens_at, 0, 5).'–'.substr((string) $record->closes_at, 0, 5)),
                TextColumn::make('reason')->label('Motiv')->placeholder('—'),
            ])
            ->filters([TernaryFilter::make('upcoming')->label('Doar viitoare')->default(true)->queries(true: fn ($query) => $query->where('date', '>=', today()), false: fn ($query) => $query->where('date', '<', today()))])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageScheduleExceptions::route('/')];
    }
}
