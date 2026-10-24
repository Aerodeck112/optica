<?php

namespace App\Filament\Resources\ContactImports;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Marketing\Models\ContactImport;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactImportResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = ContactImport::class;

    protected static string $viewPermission = 'marketing.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $modelLabel = 'import contacte';

    protected static ?string $pluralModelLabel = 'Importuri contacte';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('CSV cu coloanele: email, phone, first_name, last_name, company_name, consent_email (da/nu), consent_sms (da/nu), consent_date (AAAA-LL-ZZ). Consimțământul se înregistrează doar pe rândurile cu acord explicit și dată. Nu importa liste cumpărate sau adrese fără acord.'),
            FileUpload::make('file')->label('Fișier CSV')->disk('local')->directory('imports')->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->maxSize(10240)->required()
                ->storeFileNamesIn('filename'),
            TextInput::make('source_description')->label('Sursa contactelor')->required()->maxLength(255)->placeholder('ex. formulare de înscriere la recepție, eveniment X'),
            Textarea::make('permission_proof')->label('Dovada permisiunii')->required()->maxLength(1000)->placeholder('ex. formulare semnate păstrate la sediu, nr. …; textul acordului afișat'),
            CheckboxList::make('channels')->label('Canale pentru care rândurile conțin acord')->options(['email' => 'E-mail', 'sms' => 'SMS'])->required(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
            TextColumn::make('filename')->label('Fișier'),
            TextColumn::make('source_description')->label('Sursă')->limit(40),
            TextColumn::make('rows_imported')->label('Importate'),
            TextColumn::make('rows_skipped')->label('Omise'),
            TextColumn::make('status')->label('Stare')->badge(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContactImports::route('/'),
            'create' => Pages\CreateContactImport::route('/create'),
        ];
    }
}
