<?php

namespace App\Filament\Resources\AiKnowledgeEntries;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Ai\Models\AiKnowledgeEntry;
use App\Modules\Ai\Services\KnowledgeBase;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Baza de cunoștințe a asistentului: doar intrările aprobate ajung la model. Orice modificare readuce intrarea
 * în „ciornă”, ca informațiile publice să fie mereu verificate de o persoană.
 */
class AiKnowledgeEntryResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = AiKnowledgeEntry::class;

    protected static string $viewPermission = 'ai.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Asistent AI';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $modelLabel = 'informație';

    protected static ?string $pluralModelLabel = 'Bază de cunoștințe';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Scrie doar informații confirmate pentru arena din Cluj. Întrebările frecvente marcate „AI” și datele din Setări sunt incluse automat.'),
            TextInput::make('topic')->label('Subiect')->required()->maxLength(120),
            Textarea::make('content')->label('Conținut')->required()->rows(6)->maxLength(4000),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('topic')
            ->columns([
                TextColumn::make('topic')->label('Subiect')->searchable()->description(fn (AiKnowledgeEntry $record) => str($record->content)->limit(100)),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => $state === 'approved' ? 'Aprobată' : 'Ciornă — nu e folosită')
                    ->color(fn ($state) => $state === 'approved' ? 'success' : 'warning'),
                TextColumn::make('approved_at')->label('Aprobată')->dateTime('d.m.Y', config('aw.timezone'))->placeholder('—'),
            ])
            ->headerActions([
                Action::make('preview')->label('Vezi ce primește asistentul')->icon('heroicon-o-eye')->color('gray')
                    ->modalContent(fn () => new HtmlString('<pre style="white-space:pre-wrap;font-size:.8rem;max-height:65vh;overflow:auto">'.e(app(KnowledgeBase::class)->render()).'</pre>'))
                    ->modalSubmitAction(false)->modalWidth('4xl'),
            ])
            ->recordActions([
                Action::make('approve')->label('Aprobă')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (AiKnowledgeEntry $record) => $record->status !== 'approved')
                    ->requiresConfirmation()->modalDescription('Confirm că informația este corectă și poate fi comunicată clienților.')
                    ->action(function (AiKnowledgeEntry $record) {
                        $record->forceFill(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()])->save();
                        Notification::make()->success()->title('Informația a fost aprobată.')->send();
                    }),
                EditAction::make()->mutateDataUsing(fn (array $data) => $data + ['status' => 'draft', 'approved_by' => null, 'approved_at' => null]),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAiKnowledgeEntries::route('/')];
    }
}
