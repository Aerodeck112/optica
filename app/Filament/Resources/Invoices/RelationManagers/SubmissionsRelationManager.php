<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Modules\Finance\Models\EfacturaSubmission;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    protected static ?string $title = 'Transmiteri e-Factura';

    public const STATUSES = [
        'generated' => 'Generat', 'validated' => 'Validat local', 'validation_failed' => 'Erori de validare', 'uploading' => 'Se transmite',
        'uploaded' => 'Trimis', 'in_processing' => 'În prelucrare la ANAF', 'accepted' => 'Acceptat', 'rejected' => 'Respins', 'error' => 'Rezultat incert',
    ];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->status === 'issued';
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Generat')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextColumn::make('environment')->label('Mediu')->badge()->formatStateUsing(fn ($state) => $state === 'prod' ? 'Producție' : 'Test')->color(fn ($state) => $state === 'prod' ? 'success' : 'warning'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'accepted' => 'success', 'rejected', 'validation_failed', 'error' => 'danger', default => 'warning'
                    }),
                TextColumn::make('upload_index')->label('Index încărcare')->placeholder('—')->copyable(),
                TextColumn::make('errors')->label('Erori')->state(fn (EfacturaSubmission $record) => implode(' · ', array_merge((array) $record->validation_errors, (array) $record->anaf_errors)) ?: null)
                    ->placeholder('—')->wrap()->limit(200)->color('danger'),
                IconColumn::make('is_current')->label('Curentă')->boolean(),
            ])
            ->recordActions([
                Action::make('xml')->label('XML')->icon('heroicon-o-code-bracket')->color('gray')->url(fn (EfacturaSubmission $record) => route('admin.efactura.file', [$record, 'xml'])),
                Action::make('response')->label('Răspuns ANAF')->icon('heroicon-o-archive-box-arrow-down')->color('gray')
                    ->visible(fn (EfacturaSubmission $record) => filled($record->response_path))->url(fn (EfacturaSubmission $record) => route('admin.efactura.file', [$record, 'response'])),
            ]);
    }
}
