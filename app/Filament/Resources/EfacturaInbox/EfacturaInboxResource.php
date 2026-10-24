<?php

namespace App\Filament\Resources\EfacturaInbox;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Modules\Finance\Models\EfacturaInboxMessage;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EfacturaInboxResource extends Resource
{
    use ChecksPermissions;

    public const STATUSES = ['new' => 'Nou', 'downloaded' => 'De înregistrat', 'parsed' => 'De înregistrat', 'linked' => 'Înregistrată', 'ignored' => 'Ignorată', 'error' => 'Eroare la descărcare'];

    protected static ?string $model = EfacturaInboxMessage::class;

    protected static string $viewPermission = 'inventory.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Stocuri';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $modelLabel = 'factură primită (SPV)';

    protected static ?string $pluralModelLabel = 'Facturi primite (SPV)';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return static::userCan('inventory.manage') || static::userCan('efactura.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = EfacturaInboxMessage::query()->whereIn('status', ['downloaded', 'parsed'])->count();

        return $open ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('anaf_created_at', 'desc')
            ->columns([
                TextColumn::make('anaf_created_at')->label('Primită')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                TextColumn::make('supplier')->label('Furnizor')->state(fn (EfacturaInboxMessage $record) => $record->parsed['supplier_name'] ?? null)->placeholder('—')
                    ->description(fn (EfacturaInboxMessage $record) => 'CUI '.($record->parsed['supplier_tax_id'] ?? $record->issuer_tax_id)),
                TextColumn::make('number')->label('Număr')->state(fn (EfacturaInboxMessage $record) => $record->parsed['number'] ?? null)->placeholder('—'),
                TextColumn::make('total')->label('Total')->state(fn (EfacturaInboxMessage $record) => isset($record->parsed['total']) ? number_format((float) $record->parsed['total'], 2, ',', '.').' '.($record->parsed['currency'] ?? 'RON') : null)->placeholder('—')->alignEnd(),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'linked' => 'success', 'error' => 'danger', 'ignored' => 'gray', default => 'warning'
                    }),
            ])
            ->filters([SelectFilter::make('status')->label('Stare')->options(['downloaded' => 'De înregistrat', 'linked' => 'Înregistrată', 'ignored' => 'Ignorată', 'error' => 'Eroare'])])
            ->recordActions([
                Action::make('register')->label('Înregistrează')->icon('heroicon-o-document-plus')->color('success')
                    ->visible(fn (EfacturaInboxMessage $record) => in_array($record->status, ['downloaded', 'parsed'], true) && auth()->user()?->can('inventory.manage'))
                    ->requiresConfirmation()
                    ->modalDescription('Se creează factura de achiziție (furnizorul se identifică după CUI sau se adaugă). Asociați apoi liniile cu articolele din stoc și faceți recepția.')
                    ->action(function (EfacturaInboxMessage $record, $livewire) {
                        try {
                            $invoice = app(PurchaseInvoiceService::class)->fromInbox($record, auth()->id());
                            $livewire->redirect(PurchaseInvoiceResource::getUrl('edit', ['record' => $invoice]));
                        } catch (InventoryException $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        }
                    }),
                Action::make('open')->label('Deschide')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->visible(fn (EfacturaInboxMessage $record) => (bool) $record->purchase_invoice_id)
                    ->url(fn (EfacturaInboxMessage $record) => PurchaseInvoiceResource::getUrl('view', ['record' => $record->purchase_invoice_id])),
                Action::make('ignore')->label('Ignoră')->icon('heroicon-o-eye-slash')->color('gray')
                    ->visible(fn (EfacturaInboxMessage $record) => in_array($record->status, ['downloaded', 'parsed', 'error'], true))
                    ->requiresConfirmation()->modalDescription('Folosiți pentru facturi care nu țin de stocuri (utilități, servicii) — contabilul le preia din SPV.')
                    ->action(fn (EfacturaInboxMessage $record) => $record->forceFill(['status' => 'ignored'])->save()),
            ])
            ->emptyStateHeading('Nicio factură primită')
            ->emptyStateDescription('Facturile de la furnizori se descarcă automat din SPV după autorizarea integrării e-Factura (verificare orară).');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEfacturaInbox::route('/')];
    }
}
