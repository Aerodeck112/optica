<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Finance\Efactura\CuiValidator;
use App\Modules\Inventory\Models\Supplier;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Supplier::class;

    protected static string $viewPermission = 'inventory.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Stocuri';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $modelLabel = 'furnizor';

    protected static ?string $pluralModelLabel = 'Furnizori';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Denumire')->required()->maxLength(190)->columnSpanFull(),
            TextInput::make('tax_id')->label('CUI / CIF')->maxLength(20)
                ->helperText('Pentru furnizorii din România se verifică cifra de control.')
                ->rule(fn ($get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                    if (filled($value) && strtoupper((string) ($get('country') ?: 'RO')) === 'RO' && ! CuiValidator::isValid((string) $value)) {
                        $fail('CUI-ul nu are cifra de control corectă.');
                    }
                }),
            TextInput::make('reg_no')->label('Nr. Reg. Com.')->maxLength(40),
            TextInput::make('address')->label('Adresă')->maxLength(255)->columnSpanFull(),
            TextInput::make('city')->label('Localitate')->maxLength(120),
            TextInput::make('country')->label('Țară (cod)')->maxLength(2)->default('RO'),
            TextInput::make('iban')->label('IBAN')->maxLength(40),
            TextInput::make('bank')->label('Banca')->maxLength(120),
            TextInput::make('contact_name')->label('Persoană de contact')->maxLength(120),
            TextInput::make('email')->label('E-mail')->email()->maxLength(190),
            TextInput::make('phone')->label('Telefon')->tel()->maxLength(40),
            Toggle::make('is_vat_payer')->label('Plătitor de TVA'),
            Textarea::make('notes')->label('Note')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Denumire')->searchable()->sortable(),
                TextColumn::make('tax_id')->label('CUI')->searchable()->placeholder('—'),
                TextColumn::make('contact_name')->label('Contact')->placeholder('—'),
                TextColumn::make('phone')->label('Telefon')->placeholder('—'),
                TextColumn::make('purchase_invoices_count')->label('Facturi')->counts('purchaseInvoices'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSuppliers::route('/')];
    }
}
