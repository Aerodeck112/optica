<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Concerns\ChecksPermissions;
use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = AuditLog::class;

    protected static string $viewPermission = 'audit.view';

    protected static ?string $managePermission = null;

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $modelLabel = 'eveniment audit';

    protected static ?string $pluralModelLabel = 'Jurnal de audit';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('created_at')->label('Data')->dateTime('d.m.Y H:i:s', config('aw.timezone')),
                TextEntry::make('action')->label('Acțiune'),
                TextEntry::make('actor')->label('Cine')->state(fn (AuditLog $record) => self::actor($record)),
                TextEntry::make('subject')->label('Obiect')->state(fn (AuditLog $record) => $record->subject_type ? "{$record->subject_type} #{$record->subject_id}" : '—'),
            ]),
            KeyValueEntry::make('changes')->label('Detalii')->state(fn (AuditLog $record) => collect((array) $record->changes)->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE))->all()),
        ])->columns(1);
    }

    public static function actor(AuditLog $record): string
    {
        return match ($record->actor_type) {
            'user' => User::query()->find($record->actor_id)?->name ?? 'Utilizator #'.$record->actor_id,
            'customer' => 'Client #'.$record->actor_id,
            default => 'Sistem',
        };
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone'))->sortable(),
                TextColumn::make('action')->label('Acțiune')->searchable()->badge(),
                TextColumn::make('actor')->label('Cine')->state(fn (AuditLog $record) => self::actor($record)),
                TextColumn::make('subject')->label('Obiect')->state(fn (AuditLog $record) => $record->subject_type ? "{$record->subject_type} #{$record->subject_id}" : '—'),
            ])
            ->filters([
                SelectFilter::make('actor_id')->label('Utilizator')->options(fn () => User::query()->pluck('name', 'id')),
                SelectFilter::make('prefix')->label('Domeniu')->options(['booking' => 'Rezervări', 'customer' => 'Clienți', 'cms' => 'Conținut', 'settings' => 'Setări', 'integration' => 'Integrări', 'user' => 'Utilizatori', 'invoice' => 'Facturi', 'voucher' => 'Vouchere', 'loyalty' => 'Fidelizare', 'legal' => 'Documente juridice'])
                    ->query(fn ($query, array $data) => $data['value'] ? $query->where('action', 'like', $data['value'].'.%') : $query),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
