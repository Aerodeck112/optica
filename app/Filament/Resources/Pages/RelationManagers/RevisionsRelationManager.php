<?php

namespace App\Filament\Resources\Pages\RelationManagers;

use App\Modules\Cms\Models\PageRevision;
use App\Modules\Cms\Services\PageEditor;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Istoric revizii';

    public const REASONS = [
        'draft_saved' => 'Ciornă salvată',
        'published' => 'Publicare',
        'scheduled' => 'Publicare programată',
        'restored' => 'Restaurare',
        'draft_discarded' => 'Ciornă eliminată',
        'unpublished' => 'Retragere de pe site',
    ];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextColumn::make('reason')->label('Acțiune')->badge()->formatStateUsing(fn ($state) => self::REASONS[$state] ?? $state),
                TextColumn::make('user.name')->label('Utilizator')->placeholder('Sistem'),
                TextColumn::make('note')->label('Notă')->placeholder('—')->limit(60),
                TextColumn::make('blocks_count')->label('Blocuri (RO)')->state(fn (PageRevision $r) => count($r->snapshot['blocks']['ro'] ?? [])),
            ])
            ->recordActions([
                Action::make('restore')
                    ->label('Restaurează în ciornă')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Conținutul reviziei devine ciorna curentă. Site-ul se schimbă doar după „Publică”.')
                    ->visible(fn () => auth()->user()?->can('cms.manage'))
                    ->action(function (PageRevision $record) {
                        app(PageEditor::class)->restore($record, auth()->id());
                        Notification::make()->success()->title('Revizia a fost restaurată în ciornă. Reîncarcă pagina pentru a o vedea în formular.')->send();
                        $this->redirect(request()->header('Referer') ?: url()->current());
                    }),
            ])
            ->paginated([10, 25]);
    }
}
