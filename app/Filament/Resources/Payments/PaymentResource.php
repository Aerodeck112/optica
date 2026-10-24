<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Modules\Finance\Models\Payment;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Payment::class;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = null;

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Financiar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $modelLabel = 'încasare';

    protected static ?string $pluralModelLabel = 'Încasări';

    protected static ?int $navigationSort = 2;

    public static function canEdit(Model $record): bool
    {
        return false; // o încasare nu se modifică; se rambursează, cu istoric
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'succeeded' => 'success',
            'pending' => 'warning',
            'failed', 'cancelled' => 'gray',
            'refunded', 'partially_refunded' => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking', 'customer', 'recorder']))
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone'))->sortable(),
                TextColumn::make('booking.code')->label('Rezervare')->fontFamily('mono')->searchable()
                    ->url(fn (Payment $record) => $record->booking ? BookingResource::getUrl('view', ['record' => $record->booking]) : null),
                TextColumn::make('customer_name')->label('Client')->state(fn (Payment $record) => $record->customer?->fullName())->placeholder('—'),
                TextColumn::make('method')->label('Metodă')->formatStateUsing(fn ($state) => Payment::METHODS[$state] ?? $state),
                TextColumn::make('amount_minor')->label('Sumă')->formatStateUsing(fn ($state) => Money::format($state))->alignEnd(),
                TextColumn::make('refunded_minor')->label('Rambursat')->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—')->alignEnd(),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Payment::STATUSES[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                TextColumn::make('recorder.name')->label('Înregistrat de')->placeholder('Online')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stare')->options(Payment::STATUSES)->multiple(),
                SelectFilter::make('method')->label('Metodă')->options(Payment::METHODS)->multiple(),
                Filter::make('today')->label('Doar azi')->query(fn (Builder $query) => $query->where('created_at', '>=', now(config('aw.timezone'))->startOfDay()->utc())),
            ])
            ->recordActions([ViewAction::make(), PaymentActions::fiscalReceipt(), PaymentActions::requestRefund()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Încasare')->columns(3)->schema([
                TextEntry::make('amount_minor')->label('Sumă')->formatStateUsing(fn ($state) => Money::format($state)),
                TextEntry::make('method')->label('Metodă')->formatStateUsing(fn ($state) => Payment::METHODS[$state] ?? $state),
                TextEntry::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Payment::STATUSES[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                TextEntry::make('booking.code')->label('Rezervare')->placeholder('—'),
                TextEntry::make('succeeded_at')->label('Încasată la')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                TextEntry::make('refunded_minor')->label('Rambursat')->formatStateUsing(fn ($state) => Money::format($state)),
                TextEntry::make('provider')->label('Procesator')->placeholder('—'),
                TextEntry::make('provider_reference')->label('Referință procesator')->placeholder('—')->copyable(),
                TextEntry::make('note')->label('Notă')->placeholder('—'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\RefundsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }
}
