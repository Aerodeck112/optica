<?php

namespace App\Filament\Resources\Vouchers;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Catalog\Models\Package;
use App\Modules\Crm\Models\Customer;
use App\Modules\Loyalty\Models\Voucher;
use App\Modules\Loyalty\Services\LoyaltyException;
use App\Modules\Loyalty\Services\VoucherService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VoucherResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Voucher::class;

    protected static string $viewPermission = 'vouchers.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Fidelizare';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $modelLabel = 'voucher';

    protected static ?string $pluralModelLabel = 'Vouchere cadou';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'code';

    public static function canEdit($record): bool
    {
        return false; // voucherele emise nu se modifică; se anulează și se emit din nou, cu istoric
    }

    public const ORIGINS = ['sale' => 'Vândut', 'reward' => 'Recompensă fidelitate', 'promo' => 'Promoțional', 'compensation' => 'Compensație'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Select::make('type')->label('Tip')->options(['value' => 'Valoric (sumă)', 'package' => 'Pentru un pachet'])->required()->live()->default('value'),
                Fields::money('initial_value_minor', 'Valoare')->visible(fn (Get $get) => $get('type') === 'value')->required(fn (Get $get) => $get('type') === 'value'),
                Select::make('package_id')->label('Pachet')->visible(fn (Get $get) => $get('type') === 'package')->required(fn (Get $get) => $get('type') === 'package')
                    ->options(fn () => Package::query()->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('name', 'ro')])),
                TextInput::make('package_participants')->label('Pentru câte persoane')->integer()->minValue(1)->visible(fn (Get $get) => $get('type') === 'package'),
                Toggle::make('allows_partial_use')->label('Se poate folosi în mai multe tranșe')->default(true)->inline(false)->visible(fn (Get $get) => $get('type') === 'value'),
                Select::make('origin')->label('Origine')->options(self::ORIGINS)->required()->default('sale'),
                DatePicker::make('valid_from')->label('Valabil de la')->default(now())->required(),
                DatePicker::make('valid_until')->label('Valabil până la')->afterOrEqual('valid_from')
                    ->helperText('Perioada de valabilitate trebuie să corespundă condițiilor publicate pentru vouchere.'),
            ]),
            Section::make('Cumpărător și destinatar')->schema([
                Grid::make(2)->schema([
                    Select::make('buyer_customer_id')->label('Cumpărător (client)')->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Customer::query()->whereNull('anonymized_at')->where(fn ($q) => $q->where('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => $c->fullName().' · '.($c->email ?? $c->phone)])->all())
                        ->getOptionLabelUsing(fn ($value) => Customer::query()->find($value)?->fullName()),
                    TextInput::make('recipient_name')->label('Numele destinatarului (tipărit pe voucher)')->maxLength(120),
                ]),
                Textarea::make('message')->label('Mesaj (tipărit pe voucher)')->maxLength(300)->rows(2),
            ]),
        ])->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(4)->schema([
                TextEntry::make('code')->label('Cod')->fontFamily('mono')->weight('bold')->copyable(),
                TextEntry::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Voucher::STATUSES[$state] ?? $state),
                TextEntry::make('balance_minor')->label('Sold')->formatStateUsing(fn ($state, Voucher $record) => $record->type === 'package' ? '—' : Money::format((int) $state)),
                TextEntry::make('valid_until')->label('Valabil până la')->date('d.m.Y')->placeholder('Fără termen'),
                TextEntry::make('type')->label('Tip')->formatStateUsing(fn ($state, Voucher $record) => $state === 'package' ? 'Pachet: '.$record->package?->getTranslation('name', 'ro').($record->package_participants ? " ({$record->package_participants} pers.)" : '') : 'Valoric · '.Money::format($record->initial_value_minor)),
                TextEntry::make('origin')->label('Origine')->formatStateUsing(fn ($state) => self::ORIGINS[$state] ?? $state),
                TextEntry::make('buyer.email')->label('Cumpărător')->state(fn (Voucher $record) => $record->buyer?->fullName())->placeholder('—'),
                TextEntry::make('recipient_name')->label('Destinatar')->placeholder('—'),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->label('Cod')->searchable()->fontFamily('mono')->copyable(),
                TextColumn::make('type')->label('Tip')->formatStateUsing(fn ($state, Voucher $record) => $state === 'package' ? 'Pachet' : Money::format($record->initial_value_minor)),
                TextColumn::make('balance_minor')->label('Sold')->formatStateUsing(fn ($state, Voucher $record) => $record->type === 'package' ? '—' : Money::format((int) $state)),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Voucher::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'redeemed' => 'info', 'expired', 'cancelled' => 'gray', default => 'warning'
                    }),
                TextColumn::make('valid_until')->label('Valabil până la')->date('d.m.Y')->placeholder('—')->sortable(),
                TextColumn::make('recipient_name')->label('Destinatar')->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->label('Stare')->options(Voucher::STATUSES)])
            ->recordActions([ViewAction::make(), ActionGroup::make(self::actions())]);
    }

    public static function actions(): array
    {
        return [
            Action::make('print')->label('Tipărește')->icon('heroicon-o-printer')
                ->url(fn (Voucher $record) => route('admin.vouchers.print', $record), shouldOpenInNewTab: true),
            Action::make('redeem')->label('Folosește')->icon('heroicon-o-ticket')->color('success')
                ->visible(fn (Voucher $record) => $record->status === 'active')
                ->schema(fn (Voucher $record) => $record->type === 'value' ? [Fields::money('amount', 'Suma folosită')->required()->default($record->allows_partial_use ? null : $record->balance_minor), TextInput::make('note')->label('Notă (ex. cod rezervare)')->maxLength(200)] : [TextInput::make('note')->label('Notă (ex. cod rezervare)')->maxLength(200)])
                ->action(function (Voucher $record, array $data) {
                    try {
                        $tx = app(VoucherService::class)->redeem($record->code, (int) ($data['amount'] ?? 0), 'manual:'.$record->id.':'.now()->format('YmdHisv'), null, auth()->id());
                        if (! empty($data['note'])) {
                            $tx->forceFill(['note' => $data['note']])->save();
                        }
                        Notification::make()->success()->title('Voucher folosit. Sold rămas: '.Money::format((int) $record->refresh()->balance_minor))->send();
                    } catch (LoyaltyException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('cancel')->label('Anulează')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Voucher $record) => in_array($record->status, ['active', 'draft'], true))
                ->schema([Textarea::make('reason')->label('Motiv')->required()->maxLength(255)])
                ->requiresConfirmation()
                ->action(function (Voucher $record, array $data) {
                    app(VoucherService::class)->cancel($record, auth()->id(), $data['reason']);
                    Notification::make()->success()->title('Voucherul a fost anulat.')->send();
                }),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\TransactionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVouchers::route('/'),
            'create' => Pages\CreateVoucher::route('/create'),
            'view' => Pages\ViewVoucher::route('/{record}'),
        ];
    }
}
