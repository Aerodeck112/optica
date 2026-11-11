<?php

namespace App\Filament\Resources\PlayerWaivers;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Booking\Services\PlayerWaiverService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Declarațiile de participare semnate: doar citire și verificare la recepție (date personale, acces restrâns). */
class PlayerWaiverResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = PlayerWaiver::class;

    protected static string $viewPermission = 'waivers.view';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Recepție';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $modelLabel = 'declarație de participare';

    protected static ?string $pluralModelLabel = 'Declarații de participare';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(4)->schema([
                TextEntry::make('participant_name')->label('Participant')->weight('bold'),
                TextEntry::make('participant_birth_date')->label('Data nașterii')->date('d.m.Y'),
                TextEntry::make('is_minor')->label('Minor')->badge()->formatStateUsing(fn ($state) => $state ? 'Da — semnează părintele' : 'Nu')->color(fn ($state) => $state ? 'warning' : 'gray'),
                TextEntry::make('booking.code')->label('Rezervare')->placeholder('Fără rezervare (recepție)'),
                TextEntry::make('signed_at')->label('Semnată')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextEntry::make('source')->label('Unde')->formatStateUsing(fn ($state) => match ($state) {
                    'reception' => 'La recepție (cod QR)', 'tablet' => 'Pe tableta de la recepție (cod de 6 cifre)', default => 'Online (link rezervare)'
                }),
                TextEntry::make('verified_at')->label('Verificată la recepție')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('Neverificată'),
                TextEntry::make('verifier.name')->label('Verificată de')->placeholder('—'),
            ]),
            Section::make('Părinte / reprezentant legal')->visible(fn (PlayerWaiver $record) => $record->is_minor)->schema([
                Grid::make(3)->schema([
                    TextEntry::make('guardian_name')->label('Nume'),
                    TextEntry::make('guardian_relationship')->label('Relația cu minorul')->placeholder('—'),
                    TextEntry::make('guardian_phone')->label('Telefon')->placeholder('—'),
                ]),
            ]),
            Section::make('Semnătură și dovadă')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('signer_name')->label('Semnatar'),
                    TextEntry::make('signer_role')->label('Calitate')->formatStateUsing(fn ($state) => $state === 'guardian' ? 'Părinte / reprezentant legal' : 'Participant major, în nume propriu'),
                    TextEntry::make('email')->label('E-mail')->placeholder('—'),
                    TextEntry::make('document_version')->label('Versiunea documentului'),
                    TextEntry::make('document_hash')->label('Amprenta textului acceptat')->fontFamily('mono')->limit(20)->tooltip(fn (PlayerWaiver $record) => $record->document_hash),
                    TextEntry::make('locale')->label('Limba')->formatStateUsing(fn ($state) => strtoupper((string) $state)),
                    TextEntry::make('marketing_email')->label('Acord e-mail marketing')->formatStateUsing(fn ($state) => $state ? 'Da' : 'Nu'),
                    TextEntry::make('photo_consent')->label('Acord foto/video')->formatStateUsing(fn ($state) => $state ? 'Da' : 'Nu'),
                ]),
                ImageEntry::make('signature')->label('Semnătura')->state(fn (PlayerWaiver $record) => $record->signature)->imageHeight(110)->extraImgAttributes(['style' => 'background:#fff;border-radius:.5rem;padding:.25rem']),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('signed_at', 'desc')
            ->columns([
                TextColumn::make('participant_name')->label('Participant')->searchable()
                    ->description(fn (PlayerWaiver $record) => $record->is_minor ? 'Minor · semnat de '.$record->guardian_name : null),
                TextColumn::make('booking.code')->label('Rezervare')->searchable()->placeholder('—'),
                TextColumn::make('signed_at')->label('Semnată')->dateTime('d.m.Y H:i', config('aw.timezone'))->sortable(),
                TextColumn::make('verified_at')->label('Recepție')->badge()
                    ->state(fn (PlayerWaiver $record) => $record->verified_at ? 'Validată' : 'Neverificată')
                    ->color(fn (PlayerWaiver $record) => $record->verified_at ? 'success' : 'warning'),
            ])
            ->filters([
                Filter::make('today')->label('Semnate azi')->query(fn (Builder $query) => $query->whereDate('signed_at', today())),
                TernaryFilter::make('verified')->label('Verificată la recepție')->nullable()->attribute('verified_at'),
                TernaryFilter::make('is_minor')->label('Minor'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::verifyAction(),
                Action::make('print')->label('Tipărește')->icon('heroicon-o-printer')->color('gray')
                    ->url(fn (PlayerWaiver $record) => route('admin.waivers.print', $record))->openUrlInNewTab(),
            ]);
    }

    public static function verifyAction(): Action
    {
        return Action::make('verify')->label('Validează')->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (PlayerWaiver $record) => ! $record->verified_at)
            ->requiresConfirmation()
            ->modalDescription('Confirmați că ați verificat la recepție identitatea semnatarului (și, pentru minori, că semnează părintele sau reprezentantul legal).')
            ->action(function (PlayerWaiver $record) {
                app(PlayerWaiverService::class)->verify($record, auth()->user());
                Notification::make()->success()->title('Declarația a fost validată.')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlayerWaivers::route('/'),
            'view' => Pages\ViewPlayerWaiver::route('/{record}'),
        ];
    }
}
