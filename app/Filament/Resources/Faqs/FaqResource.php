<?php

namespace App\Filament\Resources\Faqs;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\Faq;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FaqResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Faq::class;

    protected static string $viewPermission = 'cms.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $modelLabel = 'întrebare frecventă';

    protected static ?string $pluralModelLabel = 'Întrebări frecvente';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::localeTabs(fn (string $l, bool $d) => [
                TextInput::make("question.{$l}")->label('Întrebare')->required($d)->maxLength(200),
                MarkdownEditor::make("answer.{$l}")->label('Răspuns')->required($d)->toolbarButtons([['bold', 'italic', 'link'], ['bulletList'], ['undo', 'redo']])->maxLength(3000),
            ], 'faq'),
            Grid::make(3)->schema([
                TextInput::make('category')->label('Categorie')->required()->default('general')->maxLength(40)->datalist(fn () => Faq::query()->distinct()->pluck('category')->all()),
                Toggle::make('is_published')->label('Publicată')->inline(false),
                Toggle::make('use_in_ai')->label('Sursă pentru asistentul AI')->inline(false)->default(true),
            ]),
            Fields::publishedLocales(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')
            ->columns([
                TextColumn::make('question')->label('Întrebare')->state(fn (Faq $record) => $record->getTranslation('question', 'ro'))->wrap()
                    ->searchable(query: fn ($query, string $search) => $query->where('question->ro', 'like', "%{$search}%")),
                TextColumn::make('category')->label('Categorie')->badge(),
                ToggleColumn::make('is_published')->label('Publicată'),
                ToggleColumn::make('use_in_ai')->label('AI'),
            ])
            ->filters([SelectFilter::make('category')->label('Categorie')->options(fn () => Faq::query()->distinct()->pluck('category', 'category')->all())])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageFaqs::route('/')];
    }
}
