<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Concerns\ChecksPermissions;
use App\Models\User;
use App\Modules\Crm\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Task::class;

    protected static string $viewPermission = 'tasks.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    protected static ?string $modelLabel = 'sarcină';

    protected static ?string $pluralModelLabel = 'Sarcini';

    protected static ?int $navigationSort = 3;

    public const PRIORITIES = ['low' => 'Scăzută', 'normal' => 'Normală', 'high' => 'Ridicată'];

    public static function getNavigationBadge(): ?string
    {
        $count = Task::query()->whereNull('completed_at')->where('assigned_to', auth()->id())->where('due_at', '<=', now()->endOfDay())->count();

        return $count ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Sarcină')->required()->maxLength(200),
            Textarea::make('description')->label('Detalii')->rows(3)->maxLength(2000),
            Grid::make(3)->schema([
                DateTimePicker::make('due_at')->label('Termen')->seconds(false)->timezone(config('aw.timezone')),
                Select::make('assigned_to')->label('Responsabil')->options(fn () => User::query()->where('is_active', true)->pluck('name', 'id'))->default(fn () => auth()->id()),
                Select::make('priority')->label('Prioritate')->options(self::PRIORITIES)->default('normal')->required(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('title')->label('Sarcină')->searchable()->description(fn (Task $record) => $record->description ? str($record->description)->limit(80) : null)
                    ->color(fn (Task $record) => $record->completed_at ? 'gray' : null),
                TextColumn::make('due_at')->label('Termen')->dateTime('d.m.Y H:i', config('aw.timezone'))->sortable()->placeholder('—')
                    ->color(fn (Task $record) => ! $record->completed_at && $record->due_at?->isPast() ? 'danger' : null),
                TextColumn::make('priority')->label('Prioritate')->badge()->formatStateUsing(fn ($state) => self::PRIORITIES[$state] ?? $state)->color(fn ($state) => $state === 'high' ? 'danger' : 'gray'),
                TextColumn::make('assignee.name')->label('Responsabil')->placeholder('—'),
                TextColumn::make('completed_at')->label('Finalizată')->dateTime('d.m H:i', config('aw.timezone'))->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('open')->label('Deschise')->default(true)->queries(true: fn (Builder $query) => $query->whereNull('completed_at'), false: fn (Builder $query) => $query->whereNotNull('completed_at')),
                Filter::make('mine')->label('Doar ale mele')->query(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
            ])
            ->recordActions([
                Action::make('done')->label('Gata')->icon('heroicon-o-check')->color('success')->visible(fn (Task $record) => ! $record->completed_at)
                    ->action(fn (Task $record) => $record->forceFill(['completed_at' => now()])->save()),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTasks::route('/')];
    }
}
