<?php

namespace App\Filament\Resources\Campaigns;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\Segment;
use App\Modules\Marketing\Services\CampaignService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class CampaignResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Campaign::class;

    protected static string $viewPermission = 'marketing.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $modelLabel = 'campanie';

    protected static ?string $pluralModelLabel = 'Campanii';

    protected static ?int $navigationSort = 1;

    public const STATUSES = ['draft' => 'Ciornă', 'scheduled' => 'Programată', 'sending' => 'Se trimite', 'sent' => 'Trimisă', 'paused' => 'Oprită (plafon cost)', 'cancelled' => 'Anulată'];

    public static function canEdit($record): bool
    {
        return static::canViewAny() && in_array($record->status, ['draft', 'paused'], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextInput::make('name')->label('Nume intern')->required()->maxLength(120),
                Select::make('channel')->label('Canal')->options(['email' => 'E-mail', 'sms' => 'SMS'])->required()->live()->default('email'),
                Select::make('segment_id')->label('Segment')->options(fn () => Segment::query()->pluck('name', 'id'))->required(),
            ]),
            TextInput::make('subject')->label('Subiect')->maxLength(150)->required(fn (Get $get) => $get('channel') === 'email')->visible(fn (Get $get) => $get('channel') === 'email'),
            TextInput::make('preheader')->label('Text de previzualizare')->maxLength(150)->visible(fn (Get $get) => $get('channel') === 'email'),
            MarkdownEditor::make('body')->label('Mesaj')->required()->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList'], ['undo', 'redo']])->maxLength(20000)
                ->visible(fn (Get $get) => $get('channel') === 'email')
                ->helperText('Linkul de dezabonare și datele firmei se adaugă automat în subsolul e-mailului. Variabile: {{customer.first_name}}.'),
            Textarea::make('body')->label('Mesaj SMS')->required()->maxLength(480)->rows(3)->live(onBlur: true)
                ->visible(fn (Get $get) => $get('channel') === 'sms')
                ->helperText(fn (Get $get) => 'Se adaugă automat „Dezabonare: link scurt”. Segmente estimate per mesaj: '.CampaignService::smsSegments(trim((string) $get('body')).' Dezabonare: '.url('/u/'.str_repeat('x', 14))).'. Diacriticele reduc lungimea unui segment de la 160 la 70 de caractere.'),
            Fields::money('cost_cap_minor', 'Plafon cost (SMS)')->visible(fn (Get $get) => $get('channel') === 'sms')->required(fn (Get $get) => $get('channel') === 'sms')
                ->helperText('Campania nu poate fi programată peste plafon și se oprește automat dacă l-ar depăși.'),
            Text::make('Fluxul: salvează → trimite un test → programează. Consimțământul, excluderile și limita de frecvență se verifică din nou pentru fiecare destinatar, chiar în momentul trimiterii.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('Campanie')->description(fn (Campaign $record) => $record->segment?->name),
                TextColumn::make('channel')->label('Canal')->badge(),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success', 'scheduled', 'sending' => 'info', 'paused' => 'warning', default => 'gray'
                    }),
                TextColumn::make('scheduled_at')->label('Programată')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                TextColumn::make('result')->label('Rezultat')->state(function (Campaign $record) {
                    $s = (array) $record->stats;
                    if (! $s) {
                        return null;
                    }
                    $m = (array) ($s['messages'] ?? []);

                    return ($s['recipients'] ?? 0).' destinatari · '.(($m['delivered'] ?? 0) + ($m['accepted'] ?? 0) + ($m['queued'] ?? 0) + ($m['sandboxed'] ?? 0)).' trimise · '.array_sum((array) ($s['skipped'] ?? [])).' omise · '.($s['unsubscribed'] ?? 0).' dezabonări';
                })->placeholder('—'),
                TextColumn::make('estimated_cost_minor')->label('Cost estimat')->formatStateUsing(fn ($state) => Money::format($state))->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('estimate')->label('Estimează destinatari / cost')->icon('heroicon-o-calculator')
                        ->action(function (Campaign $record) {
                            $e = app(CampaignService::class)->estimate($record);
                            Notification::make()->success()->title("{$e['recipients']} destinatari cu consimțământ".($record->channel === 'sms' ? " · {$e['segments_per_message']} segmente/mesaj · cost estimat ".Money::format($e['estimated_cost_minor']) : ''))->persistent()->send();
                        }),
                    Action::make('test')->label('Trimite test')->icon('heroicon-o-beaker')
                        ->visible(fn (Campaign $record) => in_array($record->status, ['draft', 'paused'], true))
                        ->schema([TextInput::make('to')->label('Destinatar test')->required()->default(fn () => auth()->user()?->email)])
                        ->action(function (Campaign $record, array $data) {
                            try {
                                app(CampaignService::class)->sendTest($record, $data['to'], auth()->user());
                                Notification::make()->success()->title('Testul a fost pus în coadă.')->send();
                            } catch (RuntimeException $e) {
                                Notification::make()->danger()->title($e->getMessage())->send();
                            }
                        }),
                    Action::make('schedule')->label('Programează')->icon('heroicon-o-calendar')->color('success')
                        ->visible(fn (Campaign $record) => in_array($record->status, ['draft', 'paused'], true) && auth()->user()?->can('marketing.send'))
                        ->schema([DateTimePicker::make('at')->label('Trimite la (ora României)')->seconds(false)->timezone(config('aw.timezone'))->required()->default(now()->addHour())])
                        ->action(function (Campaign $record, array $data) {
                            try {
                                app(CampaignService::class)->schedule($record, CarbonImmutable::parse($data['at']), auth()->user());
                                Notification::make()->success()->title('Campania a fost programată.')->send();
                            } catch (RuntimeException $e) {
                                Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                            }
                        }),
                    Action::make('cancel')->label('Anulează')->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                        ->visible(fn (Campaign $record) => in_array($record->status, ['scheduled', 'sending', 'paused'], true) && auth()->user()?->can('marketing.send'))
                        ->action(fn (Campaign $record) => app(CampaignService::class)->cancel($record, auth()->user())),
                    Action::make('refresh')->label('Actualizează statisticile')->icon('heroicon-o-arrow-path')
                        ->visible(fn (Campaign $record) => in_array($record->status, ['sending', 'sent', 'paused'], true))
                        ->action(fn (Campaign $record) => app(CampaignService::class)->refreshStats($record)),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}
