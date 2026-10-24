<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Support\Fields;
use App\Modules\Catalog\Models\VatRate;
use App\Modules\Crm\Models\Customer;
use App\Modules\Finance\Efactura\EfacturaService;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceLine;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvoiceResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Invoice::class;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = 'finance.issue';

    protected static ?string $deletePermission = 'finance.issue';

    protected static string|\UnitEnum|null $navigationGroup = 'Financiar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $modelLabel = 'factură';

    protected static ?string $pluralModelLabel = 'Facturi';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'full_number';

    public static function canViewAny(): bool
    {
        return static::userCan('finance.view') || static::userCan('finance.issue');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && static::userCan('finance.issue');
    }

    public static function canDelete(Model $record): bool
    {
        return $record->status === 'draft' && static::userCan('finance.issue'); // emise: doar storno
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function efacturaColor(?string $state): string
    {
        return match ($state) {
            'accepted' => 'success',
            'pending', 'generated', 'validated', 'sent' => 'warning',
            'rejected', 'error' => 'danger',
            default => 'gray',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cumpărător')->columnSpanFull()->schema([
                Select::make('customer_id')->label('Client')->searchable()
                    ->helperText('Pentru firme, datele de facturare (CUI, Reg. Com., adresă) se completează în fișa clientului. Fără client: „Client persoană fizică”.')
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()->whereNull('anonymized_at')->whereNull('merged_into_id')
                        ->where(fn ($query) => $query->where('email', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('company_name', 'like', "%{$search}%")->orWhere('company_tax_id', 'like', "%{$search}%"))
                        ->limit(20)->get()->mapWithKeys(fn (Customer $customer) => [$customer->id => $customer->fullName().($customer->company_tax_id ? ' · '.$customer->company_tax_id : '').' · '.($customer->email ?? $customer->phone)])->all())
                    ->getOptionLabelUsing(fn ($value) => Customer::query()->find($value)?->fullName()),
            ]),
            Section::make('Linii')->columnSpanFull()->description('Prețurile se introduc cu TVA inclus; baza și TVA se calculează la salvare, pe cote.')->schema([
                Repeater::make('lines')->hiddenLabel()->minItems(1)->defaultItems(1)->reorderable()->addActionLabel('Adaugă linie')
                    ->table([
                        TableColumn::make('Descriere')->markAsRequired(),
                        TableColumn::make('Cantitate')->markAsRequired()->width('120px'),
                        TableColumn::make('Preț unitar cu TVA')->markAsRequired()->width('180px'),
                        TableColumn::make('TVA')->width('170px'),
                    ])
                    ->schema([
                        TextInput::make('description')->required()->maxLength(255),
                        TextInput::make('quantity')->required()->numeric()->default(1),
                        Fields::money('unit_gross_minor', 'Preț unitar cu TVA')->required()->rule('regex:/^-?\d{1,7}([.,]\d{1,2})?$/'),
                        Select::make('vat_group')->native()->selectablePlaceholder(false)
                            ->options(fn ($get) => filled($get('vat_rate')) ? ['' => 'Cota avansului: '.$get('vat_rate').'%'] : PackageResource::VAT_GROUPS)->default('standard')
                            ->required(fn ($get) => blank($get('vat_rate')))->disabled(fn ($get) => filled($get('vat_rate'))),
                        Hidden::make('vat_rate'),
                        Hidden::make('vat_category'),
                        Hidden::make('advance_invoice_id'),
                        Hidden::make('item_code'),
                    ]),
            ]),
            Section::make('Detalii')->columns(2)->columnSpanFull()->schema([
                DatePicker::make('due_date')->label('Scadență')->native(false)->displayFormat('d.m.Y'),
                TextInput::make('payment_terms')->label('Condiții de plată')->maxLength(255)->placeholder('ex. Achitat la locație / OP în 5 zile'),
                Textarea::make('notes')->label('Mențiuni pe factură')->rows(2)->maxLength(1000)->columnSpanFull(),
            ]),
        ]);
    }

    /** Liniile unei ciorne în forma formularului. */
    public static function linesToForm(Invoice $invoice): array
    {
        $groups = VatRate::query()->get()->mapWithKeys(fn (VatRate $rate) => [number_format((float) $rate->rate, 2, '.', '').'|'.$rate->category_code => $rate->group]);

        return $invoice->lines->map(fn (InvoiceLine $line) => [
            'description' => $line->description,
            'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
            'unit_gross_minor' => $line->unit_price_gross_minor,
            'vat_group' => $line->advance_invoice_id ? null : ($groups[$line->vat_rate.'|'.$line->vat_category] ?? 'standard'),
            'vat_rate' => $line->advance_invoice_id ? (string) $line->vat_rate : null,
            'vat_category' => $line->advance_invoice_id ? $line->vat_category : null,
            'advance_invoice_id' => $line->advance_invoice_id,
            'item_code' => $line->item_code,
        ])->values()->all();
    }

    /** Liniile din formular pentru InvoiceService (cota fixă rămâne doar pe regularizările de avans). */
    public static function linesFromForm(array $lines): array
    {
        return array_values(array_map(fn (array $line) => array_filter([
            'description' => $line['description'],
            'quantity' => str_replace(',', '.', (string) $line['quantity']),
            'unit_gross_minor' => (int) $line['unit_gross_minor'],
            'vat_group' => filled($line['vat_rate'] ?? null) ? null : ($line['vat_group'] ?? 'standard'),
            'vat_rate' => $line['vat_rate'] ?? null,
            'vat_category' => $line['vat_category'] ?? null,
            'advance_invoice_id' => ($line['advance_invoice_id'] ?? null) ? (int) $line['advance_invoice_id'] : null,
            'item_code' => $line['item_code'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''), $lines));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'booking']))
            ->columns([
                TextColumn::make('full_number')->label('Număr')->fontFamily('mono')->placeholder('Ciornă')->searchable()->sortable(),
                TextColumn::make('issue_date')->label('Data')->date('d.m.Y')->placeholder('—')->sortable(),
                TextColumn::make('document_type')->label('Tip')->formatStateUsing(fn ($state, Invoice $record) => (Invoice::TYPES[$state] ?? $state).($record->kind === 'advance' ? ' (avans)' : '')),
                TextColumn::make('buyer')->label('Cumpărător')->state(fn (Invoice $record) => $record->buyer_snapshot['name'] ?? $record->customer?->fullName() ?? 'Client persoană fizică'),
                TextColumn::make('gross_total_minor')->label('Total')->formatStateUsing(fn ($state) => Money::format($state))->alignEnd(),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => ['draft' => 'Ciornă', 'issued' => 'Emisă', 'void' => 'Anulată'][$state] ?? $state)
                    ->color(fn ($state) => $state === 'issued' ? 'success' : 'gray'),
                TextColumn::make('efactura_status')->label('e-Factura')->badge()->formatStateUsing(fn ($state) => Invoice::EFACTURA_STATUSES[$state] ?? $state)->color(fn ($state) => self::efacturaColor($state))
                    ->description(fn (Invoice $record) => in_array($record->efactura_status, ['pending', 'validated', 'error', 'rejected'], true) && $record->issue_date
                        ? 'Termen: '.app(EfacturaService::class)->deadline($record)->format('d.m.Y') : null),
                TextColumn::make('booking.code')->label('Rezervare')->fontFamily('mono')->placeholder('—')->toggleable()
                    ->url(fn (Invoice $record) => $record->booking ? BookingResource::getUrl('view', ['record' => $record->booking]) : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stare')->options(['draft' => 'Ciornă', 'issued' => 'Emisă']),
                SelectFilter::make('efactura_status')->label('e-Factura')->options(Invoice::EFACTURA_STATUSES)->multiple(),
                Filter::make('efactura_todo')->label('De transmis în e-Factura')->query(fn (Builder $query) => $query->whereIn('efactura_status', ['pending', 'validated', 'error', 'rejected'])),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()->visible(fn (Invoice $record) => self::canEdit($record))]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Section::make('Document')->columnSpan(1)->schema([
                    TextEntry::make('full_number')->label('Număr')->placeholder('Ciornă — numărul se alocă la emitere'),
                    TextEntry::make('issue_date')->label('Data emiterii')->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('document_type')->label('Tip')->formatStateUsing(fn ($state) => Invoice::TYPES[$state] ?? $state),
                    TextEntry::make('relatedInvoice.full_number')->label('Factura stornată / de referință')->placeholder('—'),
                    TextEntry::make('efactura_status')->label('e-Factura')->badge()->formatStateUsing(fn ($state) => Invoice::EFACTURA_STATUSES[$state] ?? $state)->color(fn ($state) => self::efacturaColor($state))
                        ->visible(fn (Invoice $record) => $record->status === 'issued'),
                    TextEntry::make('efactura_deadline')->label('Termen transmitere e-Factura')
                        ->state(fn (Invoice $record) => app(EfacturaService::class)->deadline($record)->format('d.m.Y'))
                        ->visible(fn (Invoice $record) => $record->status === 'issued' && in_array($record->efactura_status, ['pending', 'validated', 'error', 'rejected'], true)),
                ]),
                Section::make('Cumpărător')->columnSpan(1)->schema([
                    TextEntry::make('buyer_name')->label('Nume')->state(fn (Invoice $record) => $record->buyer_snapshot['name'] ?? $record->customer?->fullName() ?? 'Client persoană fizică'),
                    TextEntry::make('buyer_tax')->label('CUI')->state(fn (Invoice $record) => $record->buyer_snapshot['tax_id'] ?? $record->customer?->company_tax_id)->placeholder('—'),
                    TextEntry::make('buyer_address')->label('Adresă')->state(fn (Invoice $record) => $record->buyer_snapshot['address'] ?? $record->customer?->billing_address)->placeholder('—'),
                ]),
                Section::make('Totaluri')->columnSpan(1)->schema([
                    TextEntry::make('net_total_minor')->label('Total fără TVA')->formatStateUsing(fn ($state) => Money::format($state)),
                    TextEntry::make('vat_total_minor')->label('TVA')->formatStateUsing(fn ($state) => Money::format($state)),
                    TextEntry::make('rounding_minor')->label('Ajustare rotunjire')->formatStateUsing(fn ($state) => Money::format((int) $state))->visible(fn (Invoice $record) => (int) $record->rounding_minor !== 0),
                    TextEntry::make('gross_total_minor')->label('Total de plată')->formatStateUsing(fn ($state) => Money::format($state))->weight('bold'),
                ]),
            ])->columnSpanFull(),
            Section::make('Linii')->columnSpanFull()->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->table([
                    RepeatableEntry\TableColumn::make('Descriere'),
                    RepeatableEntry\TableColumn::make('Cant.'),
                    RepeatableEntry\TableColumn::make('Preț fără TVA'),
                    RepeatableEntry\TableColumn::make('TVA'),
                    RepeatableEntry\TableColumn::make('Valoare'),
                ])->schema([
                    TextEntry::make('description'),
                    TextEntry::make('quantity')->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                    TextEntry::make('unit_price')->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').' lei'),
                    TextEntry::make('vat_rate')->formatStateUsing(fn ($state, InvoiceLine $record) => rtrim(rtrim((string) $state, '0'), '.').'% ('.$record->vat_category.')'),
                    TextEntry::make('line_gross_minor')->formatStateUsing(fn ($state) => Money::format($state)),
                ]),
            ]),
            Section::make('Mențiuni')->columnSpanFull()->collapsed()->schema([
                TextEntry::make('notes')->label('Mențiuni')->placeholder('—'),
                TextEntry::make('payment_terms')->label('Condiții de plată')->placeholder('—'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\SubmissionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'view' => Pages\ViewInvoice::route('/{record}'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
