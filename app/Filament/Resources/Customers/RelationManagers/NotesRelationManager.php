<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Note interne. Notele „restricționate” sunt vizibile doar rolurilor cu permisiunea customers.private_notes.
 * Nu se notează date medicale, CNP sau alte categorii speciale de date.
 */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Note interne';

    public function isReadOnly(): bool
    {
        return ! auth()->user()?->can('customers.manage');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->label('Notă')->required()->maxLength(2000)->rows(3)
                ->helperText('Doar informații necesare organizării. Nu nota date medicale, CNP sau alte date sensibile.'),
            Select::make('visibility')->label('Vizibilitate')->options(fn () => ['staff' => 'Echipă'] + (auth()->user()?->can('customers.private_notes') ? ['restricted' => 'Restricționată (manageri)'] : []))->default('staff')->required(),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => auth()->user()?->can('customers.private_notes') ? $query : $query->where('visibility', '!=', 'restricted'))
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextColumn::make('body')->label('Notă')->wrap(),
                TextColumn::make('user.name')->label('Autor')->placeholder('Sistem'),
                TextColumn::make('visibility')->label('Vizibilitate')->badge()->formatStateUsing(fn ($state) => $state === 'restricted' ? 'Restricționată' : 'Echipă'),
            ])
            ->headerActions([CreateAction::make()->label('Adaugă notă')->mutateDataUsing(fn (array $data) => $data + ['user_id' => auth()->id()])])
            ->recordActions([DeleteAction::make()->visible(fn ($record) => $record->user_id === auth()->id())]);
    }
}
