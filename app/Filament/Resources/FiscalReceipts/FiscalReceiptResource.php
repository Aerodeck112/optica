<?php

namespace App\Filament\Resources\FiscalReceipts;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Modules\Finance\Models\FiscalReceipt;
use App\Modules\Finance\Services\Fiscal\FiscalException;
use App\Modules\Finance\Services\Fiscal\FiscalReceiptService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FiscalReceiptResource extends Resource
{
    use ChecksPermissions;

    public const STATUSES = [
        'queued' => 'În coadă', 'dispatched' => 'Trimis la aparat', 'printed' => 'Tipărit', 'failed' => 'Eșuat',
        'uncertain' => 'Incert — de verificat', 'reconciled_printed' => 'Verificat: tipărit', 'reconciled_not_printed' => 'Verificat: netipărit', 'cancelled' => 'Anulat',
    ];

    protected static ?string $model = FiscalReceipt::class;

    protected static string $viewPermission = 'fiscal.operate';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Financiar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $modelLabel = 'bon fiscal';

    protected static ?string $pluralModelLabel = 'Bonuri fiscale';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return static::userCan('fiscal.operate') || static::userCan('fiscal.reconcile');
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
        $count = FiscalReceipt::query()->whereIn('status', ['uncertain', 'failed'])->whereDoesntHave('retries')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['device', 'booking']))
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextColumn::make('fiscal_number')->label('Nr. bon')->placeholder('—')->fontFamily('mono')
                    ->description(fn (FiscalReceipt $record) => $record->is_simulated ? FiscalReceiptService::SIMULATION_LABEL : null),
                TextColumn::make('total_minor')->label('Total')->formatStateUsing(fn ($state) => Money::format($state))->alignEnd(),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'printed', 'reconciled_printed' => 'success', 'uncertain', 'failed' => 'danger', 'queued', 'dispatched' => 'warning', default => 'gray'
                    }),
                TextColumn::make('device.name')->label('Aparat'),
                TextColumn::make('booking.code')->label('Rezervare')->fontFamily('mono')->placeholder('—')
                    ->url(fn (FiscalReceipt $record) => $record->booking ? BookingResource::getUrl('view', ['record' => $record->booking]) : null),
                TextColumn::make('last_error')->label('Eroare')->limit(50)->tooltip(fn (FiscalReceipt $record) => $record->last_error)->placeholder('—')->color('danger')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->label('Stare')->options(self::STATUSES)->multiple()])
            ->recordActions([self::reconcileAction(), self::retryAction()]);
    }

    public static function reconcileAction(): Action
    {
        return Action::make('reconcile')->label('Reconciliază')->icon('heroicon-o-scale')->color('warning')
            ->visible(fn (FiscalReceipt $record) => in_array($record->status, ['uncertain', 'dispatched'], true) && auth()->user()?->can('fiscal.reconcile'))
            ->modalDescription('Verificați în jurnalul electronic al casei de marcat (sau pe raportul Z) dacă bonul a fost tipărit. Nu reemiteți înainte de verificare.')
            ->schema([
                Radio::make('printed')->label('Rezultatul verificării')->required()->boolean('Bonul a fost tipărit', 'Bonul NU a fost tipărit'),
                TextInput::make('fiscal_number')->label('Numărul bonului din jurnal')->maxLength(40)->requiredIf('printed', true),
                Textarea::make('note')->label('Cum s-a verificat')->required()->rows(2),
            ])
            ->action(fn (FiscalReceipt $record, array $data) => self::run(fn () => app(FiscalReceiptService::class)->reconcile($record, (bool) $data['printed'], $data['fiscal_number'] ?? null, $data['note'], auth()->id()), 'Bonul a fost reconciliat.'));
    }

    public static function retryAction(): Action
    {
        return Action::make('retry')->label('Reemite')->icon('heroicon-o-arrow-path')->color('gray')
            ->visible(fn (FiscalReceipt $record) => in_array($record->status, ['failed', 'reconciled_not_printed'], true) && auth()->user()?->can('fiscal.operate') && ! $record->retries()->exists())
            ->requiresConfirmation()
            ->action(fn (FiscalReceipt $record) => self::run(fn () => app(FiscalReceiptService::class)->retry($record, auth()->id()), 'Bonul a fost pus din nou în coadă.'));
    }

    private static function run(\Closure $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (FiscalException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFiscalReceipts::route('/')];
    }
}
