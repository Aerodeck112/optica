<?php

namespace App\Filament\Resources\Segments;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Catalog\Models\Package;
use App\Modules\Loyalty\Models\LoyaltyTier;
use App\Modules\Marketing\Models\Segment;
use App\Modules\Marketing\Services\SegmentQuery;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SegmentResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Segment::class;

    protected static string $viewPermission = 'marketing.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $modelLabel = 'segment';

    protected static ?string $pluralModelLabel = 'Segmente';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nume')->required()->maxLength(120),
            Textarea::make('description')->label('Descriere')->rows(2)->maxLength(500),
            Section::make('Reguli (toate trebuie îndeplinite)')
                ->description('Indiferent de reguli, segmentul conține doar clienți cu consimțământ de marketing activ pe canalul campaniei.')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('rules.type')->label('Tip client')->options(['person' => 'Persoane', 'company' => 'Companii'])->placeholder('Oricare'),
                        TagsInput::make('rules.tags_any')->label('Etichete (oricare)'),
                        Select::make('rules.booked_package_type')->label('A rezervat')->options(Package::TYPES)->placeholder('Oricare'),
                        TextInput::make('rules.min_bookings')->label('Minimum rezervări')->integer()->minValue(0),
                        TextInput::make('rules.min_visits')->label('Minimum vizite')->integer()->minValue(0),
                        TextInput::make('rules.visited_within_days')->label('A venit în ultimele (zile)')->integer()->minValue(1),
                        TextInput::make('rules.not_visited_for_days')->label('Nu a mai venit de (zile)')->integer()->minValue(1),
                        Select::make('rules.locale')->label('Limba')->options(['ro' => 'Română', 'en' => 'English', 'hu' => 'Magyar'])->placeholder('Oricare'),
                        Select::make('rules.tier_ids')->label('Nivel fidelitate')->multiple()->options(fn () => LoyaltyTier::query()->get()->mapWithKeys(fn ($t) => [$t->id => $t->getTranslation('name', 'ro')])),
                    ]),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Segment')->description(fn (Segment $record) => $record->description),
                TextColumn::make('cached_count')->label('Destinatari (e-mail)')->placeholder('—')->description(fn (Segment $record) => $record->counted_at ? 'numărat '.$record->counted_at->diffForHumans() : null),
            ])
            ->recordActions([
                Action::make('count')->label('Numără')->icon('heroicon-o-calculator')->color('gray')
                    ->action(function (Segment $record) {
                        $email = SegmentQuery::build((array) $record->rules, 'email')->count();
                        $sms = SegmentQuery::build((array) $record->rules, 'sms')->count();
                        $record->forceFill(['cached_count' => $email, 'counted_at' => now()])->save();
                        Notification::make()->success()->title("E-mail: {$email} · SMS: {$sms} destinatari cu consimțământ")->send();
                    }),
                EditAction::make(),
                DeleteAction::make()->disabled(fn (Segment $record) => $record->campaigns()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSegments::route('/')];
    }
}
