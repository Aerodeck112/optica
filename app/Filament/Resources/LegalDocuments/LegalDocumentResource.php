<?php

namespace App\Filament\Resources\LegalDocuments;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Services\LegalDocumentService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LegalDocumentResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = LegalDocument::class;

    protected static string $viewPermission = 'legal.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $modelLabel = 'document juridic';

    protected static ?string $pluralModelLabel = 'Documente juridice';

    protected static ?int $navigationSort = 10;

    public const STATUSES = ['draft' => 'În lucru', 'published' => 'Publicat', 'superseded' => 'Înlocuit de o versiune nouă'];

    public static function canEdit(Model $record): bool
    {
        return static::userCan(static::$viewPermission) && $record->status === 'draft';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Select::make('type')->label('Document')->options(LegalDocument::TYPES)->required()->disabledOn('edit'),
                TextInput::make('version')->label('Versiune')->required()->maxLength(20)->disabledOn('edit'),
            ]),
            Section::make('Puncte de verificat de jurist / contabil')->schema([
                Repeater::make('review_flags')->label('')->schema([
                    TextInput::make('text')->label('Punct')->required()->maxLength(500),
                    Toggle::make('resolved')->label('Rezolvat')->inline(false),
                ])->columns(2)->defaultItems(0)->addActionLabel('Adaugă punct'),
            ])->collapsible(),
            Fields::localeTabs(fn (string $l, bool $d) => [
                Grid::make(2)->schema([
                    TextInput::make("title.{$l}")->label('Titlu')->required($d)->maxLength(160),
                    TextInput::make("slug.{$l}")->label('Adresă (slug)')->required($d)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->maxLength(100),
                ]),
                MarkdownEditor::make("body.{$l}")->label('Text')->required($d)->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList'], ['undo', 'redo']])->maxLength(100000)
                    ->helperText('Câmpurile {{firma}}, {{cui}}, {{adresa}}, {{email_gdpr}}, {{telefon}} se completează automat din Setări.'),
            ], 'legal'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('type')
            ->columns([
                TextColumn::make('type')->label('Document')->formatStateUsing(fn ($state) => LegalDocument::TYPES[$state] ?? $state),
                TextColumn::make('version')->label('Versiune'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'draft' => 'warning', default => 'gray'
                    }),
                TextColumn::make('effective_at')->label('În vigoare de la')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                TextColumn::make('flags')->label('De verificat')->state(fn (LegalDocument $record) => collect($record->review_flags ?? [])->where('resolved', false)->count() ?: null)
                    ->badge()->color('warning')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label('Stare')->options(self::STATUSES)->default('published')])
            ->recordActions([
                EditAction::make()->label('Editează ciorna')->visible(fn (LegalDocument $record) => $record->status === 'draft'),
                Action::make('view_public')->label('Vezi')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (LegalDocument $record) => lroute('legal.show', ['slug' => $record->getTranslation('slug', 'ro')]), shouldOpenInNewTab: true)
                    ->visible(fn (LegalDocument $record) => $record->status === 'published'),
                Action::make('new_version')->label('Versiune nouă')->icon('heroicon-o-document-duplicate')
                    ->visible(fn (LegalDocument $record) => $record->status === 'published' && static::canViewAny())
                    ->schema([TextInput::make('version')->label('Număr versiune nouă')->required()->maxLength(20)])
                    ->action(function (LegalDocument $record, array $data, $livewire) {
                        $copy = app(LegalDocumentService::class)->newVersion($record, $data['version'], auth()->id());
                        $livewire->redirect(static::getUrl('edit', ['record' => $copy]));
                    }),
                Action::make('publish')->label('Publică')->icon('heroicon-o-rocket-launch')->color('success')
                    ->visible(fn (LegalDocument $record) => $record->status === 'draft')
                    ->schema([DateTimePicker::make('effective_at')->label('Intră în vigoare la (gol = acum)')->seconds(false)->timezone(config('aw.timezone'))])
                    ->modalDescription(fn (LegalDocument $record) => $record->hasOpenReviewFlags()
                        ? 'Atenție: există puncte de verificat nerezolvate. Publică doar după validarea juridică.'
                        : 'Versiunea devine publică; acceptările noi vor face referire la ea.')
                    ->action(function (LegalDocument $record, array $data) {
                        app(LegalDocumentService::class)->publish($record, ! empty($data['effective_at']) ? CarbonImmutable::parse($data['effective_at']) : null, auth()->id());
                        Notification::make()->success()->title('Documentul a fost publicat.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegalDocuments::route('/'),
            'create' => Pages\CreateLegalDocument::route('/create'),
            'edit' => Pages\EditLegalDocument::route('/{record}/edit'),
        ];
    }
}
