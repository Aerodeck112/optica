<?php

namespace App\Filament\Resources\Leads;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Modules\Crm\Models\Lead;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LeadResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Lead::class;

    protected static string $viewPermission = 'leads.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $modelLabel = 'cerere eveniment';

    protected static ?string $pluralModelLabel = 'Cereri evenimente';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationBadge(): ?string
    {
        $count = Lead::query()->where('stage', 'new')->count();

        return $count ? (string) $count : null;
    }

    public static function stageColor(string $stage): string
    {
        return match ($stage) {
            'new' => 'warning', 'contacted', 'offer_sent', 'negotiating' => 'info', 'confirmed', 'completed' => 'success', default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Cerere')->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('reference')->label('Referință')->fontFamily('mono')->weight('bold'),
                        TextEntry::make('type')->label('Tip')->formatStateUsing(fn ($state) => Lead::TYPES[$state] ?? $state),
                        TextEntry::make('stage')->label('Etapă')->badge()->formatStateUsing(fn ($state) => Lead::STAGES[$state] ?? $state)->color(fn ($state) => self::stageColor($state)),
                        TextEntry::make('preferred_date')->label('Data dorită')->date('d.m.Y')->placeholder('—'),
                        TextEntry::make('preferred_time')->label('Ora dorită')->placeholder('—'),
                        TextEntry::make('participants')->label('Participanți')->placeholder('—'),
                        TextEntry::make('package.name')->label('Pachet')->state(fn (Lead $record) => $record->package?->getTranslation('name', 'ro'))->placeholder('—'),
                        TextEntry::make('offer_amount_minor')->label('Ofertă')->formatStateUsing(fn ($state) => Money::format($state))->placeholder('—'),
                        TextEntry::make('booking.code')->label('Rezervare')->placeholder('—')->url(fn (Lead $record) => $record->booking ? BookingResource::getUrl('view', ['record' => $record->booking]) : null),
                    ]),
                    TextEntry::make('message')->label('Mesajul clientului')->placeholder('—'),
                    TextEntry::make('lost_reason')->label('Motiv pierdere')->visible(fn (Lead $record) => $record->stage === 'lost'),
                ])->columnSpan(['lg' => 2]),
                Section::make('Contact')->schema([
                    TextEntry::make('contact_name')->label('Nume'),
                    TextEntry::make('company_name')->label('Companie')->placeholder('—'),
                    TextEntry::make('contact_phone')->label('Telefon')->placeholder('—')->url(fn ($state) => $state ? 'tel:'.$state : null),
                    TextEntry::make('contact_email')->label('E-mail')->placeholder('—')->url(fn ($state) => $state ? 'mailto:'.$state : null),
                    TextEntry::make('customer_id')->label('Fișă client')->formatStateUsing(fn () => 'Deschide')->url(fn (Lead $record) => $record->customer_id ? CustomerResource::getUrl('view', ['record' => $record->customer_id]) : null)->placeholder('—'),
                ])->columnSpan(1),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Primită')->dateTime('d.m H:i', config('aw.timezone'))->sortable(),
                TextColumn::make('contact_name')->label('Contact')->searchable(['contact_name', 'contact_email', 'contact_phone', 'company_name', 'reference'])->description(fn (Lead $record) => $record->company_name),
                TextColumn::make('type')->label('Tip')->formatStateUsing(fn ($state) => Lead::TYPES[$state] ?? $state),
                TextColumn::make('preferred_date')->label('Data dorită')->date('d.m.Y')->sortable()->placeholder('—'),
                TextColumn::make('participants')->label('Pers.')->placeholder('—'),
                TextColumn::make('stage')->label('Etapă')->badge()->formatStateUsing(fn ($state) => Lead::STAGES[$state] ?? $state)->color(fn ($state) => self::stageColor($state)),
                TextColumn::make('offer_amount_minor')->label('Ofertă')->formatStateUsing(fn ($state) => Money::format($state))->placeholder('—'),
                TextColumn::make('assignee.name')->label('Responsabil')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('stage')->label('Etapă')->options(Lead::STAGES)->multiple(),
                SelectFilter::make('type')->label('Tip')->options(Lead::TYPES),
            ])
            ->recordActions([ViewAction::make()->label('Deschide'), ActionGroup::make(LeadActions::all())]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'pipeline' => Pages\LeadPipeline::route('/pipeline'),
            'view' => Pages\ViewLead::route('/{record}'),
        ];
    }
}
