<?php

namespace App\Filament\Resources\Refunds;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Payments\PaymentActions;
use App\Filament\Resources\Payments\PaymentResource;
use App\Modules\Finance\Models\Refund;
use App\Support\Money;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RefundResource extends Resource
{
    use ChecksPermissions;

    public const STATUSES = [
        'requested' => 'De aprobat',
        'processing' => 'În procesare',
        'succeeded' => 'Returnată',
        'failed' => 'Eșuată',
        'rejected' => 'Respinsă',
    ];

    protected static ?string $model = Refund::class;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = null;

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Financiar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $modelLabel = 'rambursare';

    protected static ?string $pluralModelLabel = 'Rambursări';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $open = Refund::query()->whereIn('status', ['requested', 'processing', 'failed'])->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function columns(bool $withPayment = true): array
    {
        return array_values(array_filter([
            TextColumn::make('created_at')->label('Cerută')->dateTime('d.m.Y H:i', config('aw.timezone'))->sortable(),
            $withPayment ? TextColumn::make('payment.booking.code')->label('Rezervare')->fontFamily('mono')->placeholder('—')
                ->url(fn (Refund $record) => PaymentResource::getUrl('view', ['record' => $record->payment_id])) : null,
            TextColumn::make('amount_minor')->label('Sumă')->formatStateUsing(fn ($state) => Money::format($state))->alignEnd(),
            TextColumn::make('method')->label('Mod')->formatStateUsing(fn ($state) => $state === 'provider' ? 'Prin procesator' : 'Manual'),
            TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                ->color(fn ($state) => match ($state) {
                    'succeeded' => 'success', 'requested', 'processing' => 'warning', 'failed' => 'danger', default => 'gray'
                }),
            TextColumn::make('reason')->label('Motiv')->limit(50)->tooltip(fn (Refund $record) => $record->reason),
            TextColumn::make('error')->label('Eroare')->limit(40)->placeholder('—')->color('danger')->tooltip(fn (Refund $record) => $record->error),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('payment.booking'))
            ->columns(self::columns())
            ->filters([SelectFilter::make('status')->label('Stare')->options(self::STATUSES)->multiple()->default(['requested', 'processing', 'failed'])])
            ->recordActions([PaymentActions::approveRefund(), PaymentActions::rejectRefund()])
            ->emptyStateHeading('Nicio rambursare în așteptare');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRefunds::route('/')];
    }
}
