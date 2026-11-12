<?php

namespace App\Filament\Resources\PromoCodes;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Audit\AuditLogger;
use App\Modules\Catalog\Models\Package;
use App\Modules\Marketing\Models\PromoCode;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Codurile de reducere pentru rezervări. Modificarea lor schimbă prețul plătit, de aceea editarea cere „prices.manage”.
 * Un cod folosit nu se șterge (rămâne în istoricul rezervărilor); se dezactivează.
 */
class PromoCodeResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = PromoCode::class;

    protected static string $viewPermission = 'marketing.manage';

    protected static ?string $managePermission = 'prices.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $modelLabel = 'cod de reducere';

    protected static ?string $pluralModelLabel = 'Coduri de reducere';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('code')->label('Cod')->required()->maxLength(40)->regex('/^[A-Za-z0-9\-_ ]+$/')
                    ->dehydrateStateUsing(fn (?string $state) => PromoCode::normalize($state))
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, ?string $state) => $rule->where('code', PromoCode::normalize($state)))
                    ->helperText('Clientul îl poate scrie cu litere mari sau mici; spațiile și cratimele se ignoră.'),
                TextInput::make('name')->label('Nume intern')->required()->maxLength(120),
            ]),
            Section::make('Reducerea')->schema([
                Grid::make(3)->schema([
                    Select::make('discount_type')->label('Tip')->options(PromoCode::TYPES)->required()->default('free_participants')->live(),
                    TextInput::make('discount_value')->label('Valoare')->required()->inputMode('decimal')
                        ->rule(fn (Get $get) => $get('discount_type') === 'fixed' ? 'regex:/^\d{1,7}([.,]\d{1,2})?$/' : 'integer')
                        ->rule(fn (Get $get) => $get('discount_type') === 'percent' ? 'between:1,100' : 'not_in:0,0.0,0.00,0,00')
                        ->suffix(fn (Get $get) => match ($get('discount_type')) {
                            'percent' => '%', 'fixed' => 'lei', default => 'persoane'
                        })
                        ->formatStateUsing(fn ($state, ?PromoCode $record) => $record?->discount_type === 'fixed' && $state !== null ? Money::toDecimalString((int) $state) : $state)
                        ->dehydrateStateUsing(fn ($state, Get $get) => $get('discount_type') === 'fixed' ? Money::parse((string) $state) : (int) $state)
                        ->helperText('Participanți gratuiți: câte persoane nu plătesc pachetul (doar pachete cu preț pe persoană). Opțiunile suplimentare se plătesc.'),
                    Select::make('package_ids')->label('Doar pentru pachetele')->multiple()->placeholder('Toate pachetele')
                        ->options(fn () => Package::query()->orderBy('sort')->get()->mapWithKeys(fn (Package $p) => [$p->id => $p->getTranslation('name', 'ro')])),
                ]),
                Toggle::make('per_participant')->label('Gratuit pentru fiecare participant din rezervare')
                    ->visible(fn (Get $get) => $get('discount_type') === 'free_participants')
                    ->helperText('O persoană rezervă pentru tot grupul și nimeni nu plătește pachetul (valoarea de mai sus nu mai contează). Fiecare persoană beneficiază o singură dată: la check-in, recepția vede cine a mai folosit oferta, după declarația semnată.'),
            ]),
            Section::make('Perioade și limite')->schema([
                Grid::make(4)->schema([
                    DatePicker::make('redeem_from')->label('Se poate folosi de la')->native(false)->displayFormat('d.m.Y'),
                    DatePicker::make('redeem_until')->label('până la (inclusiv)')->native(false)->displayFormat('d.m.Y')->afterOrEqual('redeem_from'),
                    DatePicker::make('session_from')->label('Pentru sesiuni de la')->native(false)->displayFormat('d.m.Y'),
                    DatePicker::make('session_until')->label('până la (inclusiv)')->native(false)->displayFormat('d.m.Y')->afterOrEqual('session_from'),
                    TextInput::make('max_redemptions')->label('Total folosiri')->numeric()->minValue(1)->placeholder('Nelimitat'),
                    TextInput::make('max_per_customer')->label('Folosiri per client')->numeric()->minValue(1)->default(1)->placeholder('Nelimitat')
                        ->helperText('Clientul se recunoaște după e-mail sau telefon.'),
                    Toggle::make('is_active')->label('Activ')->inline(false)->default(true),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->label('Cod')->weight('bold')->copyable()->searchable()->description(fn (PromoCode $record) => $record->name),
                TextColumn::make('discount_type')->label('Reducere')->state(fn (PromoCode $record) => match ($record->discount_type) {
                    'free_participants' => $record->per_participant ? 'gratuit pentru fiecare participant' : $record->discount_value.' '.($record->discount_value === 1 ? 'persoană gratuită' : 'persoane gratuite'),
                    'percent' => $record->discount_value.'%',
                    default => Money::format($record->discount_value),
                }),
                TextColumn::make('session_until')->label('Sesiuni')->state(fn (PromoCode $record) => trim(($record->session_from?->format('d.m') ?? '…').' – '.($record->session_until?->format('d.m.Y') ?? '…'))),
                TextColumn::make('redeem_until')->label('Valabil până la')->date('d.m.Y')->placeholder('Fără termen'),
                TextColumn::make('uses')->label('Folosit')->state(fn (PromoCode $record) => $record->activeRedemptions()->count().($record->max_redemptions ? ' / '.$record->max_redemptions : '')),
                ToggleColumn::make('is_active')->label('Activ')->disabled(fn () => ! auth()->user()?->can('prices.manage')),
            ])
            ->recordActions([EditAction::make()->after(fn (PromoCode $record) => app(AuditLogger::class)->log('promo_code.updated', $record, $record->getChanges()))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePromoCodes::route('/')];
    }
}
