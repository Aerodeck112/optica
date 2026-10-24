<?php

namespace App\Filament\Resources\Testimonials;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Cms\Models\Testimonial;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Doar recenzii reale, cu sursă verificabilă și acord de publicare. Nu se scriu recenzii, nu se editează conținutul
 * și nu se afișează note agregate inventate.
 */
class TestimonialResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Testimonial::class;

    protected static string $viewPermission = 'cms.manage';

    protected static ?string $managePermission = 'cms.publish';

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $modelLabel = 'recenzie';

    protected static ?string $pluralModelLabel = 'Recenzii reale';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Introdu doar recenzii reale, primite de la clienți ai arenei din Cluj, copiate fără modificări. Păstrează linkul către sursă.'),
            Grid::make(3)->schema([
                TextInput::make('author_name')->label('Autor (cum apare la sursă)')->required()->maxLength(80),
                Select::make('source')->label('Sursă')->options(['google' => 'Google', 'facebook' => 'Facebook', 'tripadvisor' => 'Tripadvisor', 'email' => 'E-mail către noi', 'other' => 'Altă sursă'])->required(),
                TextInput::make('source_url')->label('Link către recenzie')->url()->maxLength(500)->required(fn ($get) => in_array($get('source'), ['google', 'facebook', 'tripadvisor'], true)),
                Select::make('rating')->label('Notă (de la sursă)')->options([5 => '5', 4 => '4', 3 => '3', 2 => '2', 1 => '1'])->placeholder('Fără notă'),
                DatePicker::make('reviewed_on')->label('Data recenziei')->required(),
                Select::make('locale')->label('Limba')->options(['ro' => 'Română', 'en' => 'English', 'hu' => 'Magyar'])->default('ro'),
            ]),
            Textarea::make('body')->label('Text (exact ca la sursă)')->required()->rows(4)->maxLength(2000),
            Grid::make(2)->schema([
                Toggle::make('publication_consent')->label('Avem acordul autorului / sursa permite citarea')->helperText('Fără acest acord, recenzia nu apare pe site.'),
                Toggle::make('is_published')->label('Publicată'),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')
            ->columns([
                TextColumn::make('author_name')->label('Autor')->description(fn (Testimonial $record) => str($record->body)->limit(90)),
                TextColumn::make('source')->label('Sursă'),
                TextColumn::make('rating')->label('Notă')->placeholder('—'),
                TextColumn::make('reviewed_on')->label('Data')->date('d.m.Y'),
                ToggleColumn::make('is_published')->label('Publicată')->disabled(fn (Testimonial $record) => ! $record->publication_consent),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTestimonials::route('/')];
    }
}
