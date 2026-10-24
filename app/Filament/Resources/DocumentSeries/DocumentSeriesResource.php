<?php

namespace App\Filament\Resources\DocumentSeries;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Finance\Models\DocumentSeries;
use App\Modules\Finance\Models\Invoice;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class DocumentSeriesResource extends Resource
{
    use ChecksPermissions;

    public const TYPES = ['invoice' => 'Facturi (inclusiv stornări)', 'goods_receipt' => 'Note de recepție (NIR)'];

    protected static ?string $model = DocumentSeries::class;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = 'settings.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Financiar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $modelLabel = 'serie de documente';

    protected static ?string $pluralModelLabel = 'Serii de documente';

    protected static ?int $navigationSort = 9;

    public static function lastUsedNumber(?DocumentSeries $series): int
    {
        return $series ? (int) Invoice::query()->where('series_id', $series->id)->max('number') : 0;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('document_type')->label('Documente')->options(self::TYPES)->required()->native()->disabledOn('edit'),
            TextInput::make('prefix')->label('Serie (prefix)')->required()->maxLength(20)->regex('/^[A-Z0-9-]+$/')
                ->helperText('Litere mari și cifre, ex. AWCJ. Seria apare pe document înaintea numărului.')
                ->disabled(fn (?DocumentSeries $record) => self::lastUsedNumber($record) > 0)
                ->rule(fn (?DocumentSeries $record, $get) => Rule::unique('document_series', 'prefix')->where('document_type', $get('document_type'))->ignore($record?->id)),
            TextInput::make('next_number')->label('Următorul număr')->required()->integer()->minValue(fn (?DocumentSeries $record) => self::lastUsedNumber($record) + 1)
                ->helperText(fn (?DocumentSeries $record) => self::lastUsedNumber($record) > 0
                    ? 'Ultimul număr emis: '.self::lastUsedNumber($record).'. Numărul nu poate coborî (numerotarea trebuie să fie continuă).'
                    : 'Dacă continuați o serie folosită anterior în alt program, introduceți numărul următor celui emis acolo.'),
            TextInput::make('padding')->label('Cifre')->integer()->minValue(1)->maxValue(10)->default(5)->helperText('5 → 00001'),
            Toggle::make('is_default')->label('Seria implicită pentru acest tip'),
            Toggle::make('is_active')->label('Activă')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')->label('Documente')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
                TextColumn::make('prefix')->label('Serie')->fontFamily('mono'),
                TextColumn::make('next_number')->label('Următorul')->formatStateUsing(fn ($state, DocumentSeries $record) => $record->prefix.str_pad((string) $state, (int) $record->padding, '0', STR_PAD_LEFT))->fontFamily('mono'),
                IconColumn::make('is_default')->label('Implicită')->boolean(),
                IconColumn::make('is_active')->label('Activă')->boolean(),
            ])
            ->recordActions([EditAction::make()->after(fn (DocumentSeries $record) => Pages\ManageDocumentSeries::keepSingleDefault($record))])
            ->emptyStateHeading('Nicio serie definită')
            ->emptyStateDescription('Facturile nu pot fi emise până nu definiți o serie (stabilită împreună cu contabilul).');
    }

    public static function canEdit(Model $record): bool
    {
        return static::userCan('settings.manage');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDocumentSeries::route('/')];
    }
}
