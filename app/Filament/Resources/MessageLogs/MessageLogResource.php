<?php

namespace App\Filament\Resources\MessageLogs;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Modules\Notifications\Models\MessageLog;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Jurnalul mesajelor: „în coadă” ≠ „acceptat de furnizor” ≠ „livrat” (confirmat prin webhook).
 * Destinatarul apare mascat; conținutul integral nu se păstrează în jurnal.
 */
class MessageLogResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = MessageLog::class;

    protected static string $viewPermission = 'messages.view';

    protected static ?string $managePermission = null;

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $modelLabel = 'mesaj';

    protected static ?string $pluralModelLabel = 'Jurnal mesaje';

    protected static ?int $navigationSort = 6;

    public const STATUSES = [
        'queued' => 'În coadă', 'sending' => 'Se trimite', 'accepted' => 'Acceptat de furnizor', 'delivered' => 'Livrat',
        'failed' => 'Eșuat', 'bounced' => 'Respins (bounce)', 'complained' => 'Reclamat ca spam', 'suppressed' => 'Blocat (exclus / fără consimțământ)',
        'sandboxed' => 'Sandbox (netrimis)',
    ];

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Creat')->dateTime('d.m.Y H:i', config('aw.timezone'))->sortable(),
                TextColumn::make('channel')->label('Canal')->badge(),
                TextColumn::make('category')->label('Tip')->formatStateUsing(fn ($state) => $state === 'marketing' ? 'Marketing' : 'Serviciu'),
                TextColumn::make('template_key')->label('Șablon')->fontFamily('mono')->placeholder('—'),
                TextColumn::make('recipient_masked')->label('Destinatar'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'delivered' => 'success', 'accepted' => 'info', 'failed', 'bounced', 'complained' => 'danger', 'suppressed', 'sandboxed' => 'gray', default => 'warning'
                    })
                    ->description(fn (MessageLog $record) => $record->last_error ? str($record->last_error)->limit(60) : null),
                TextColumn::make('attempts')->label('Încercări')->alignCenter(),
                TextColumn::make('provider')->label('Furnizor')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('booking.code')->label('Rezervare')->placeholder('—')
                    ->url(fn (MessageLog $record) => $record->booking_id ? BookingResource::getUrl('view', ['record' => $record->booking_id]) : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stare')->options(self::STATUSES)->multiple(),
                SelectFilter::make('channel')->label('Canal')->options(['email' => 'E-mail', 'sms' => 'SMS']),
                SelectFilter::make('category')->label('Tip')->options(['service' => 'Serviciu', 'marketing' => 'Marketing']),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMessageLogs::route('/')];
    }
}
