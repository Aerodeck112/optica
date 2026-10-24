<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Notifications\Services\TemplateRenderer;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class NotificationTemplateResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = NotificationTemplate::class;

    protected static string $viewPermission = 'templates.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $modelLabel = 'șablon de mesaj';

    protected static ?string $pluralModelLabel = 'Șabloane mesaje automate';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        $variables = fn () => function (string $attribute, $value, Closure $fail) {
            $unknown = app(TemplateRenderer::class)->unknownVariables((string) $value);
            if ($unknown) {
                $fail('Variabile necunoscute: '.implode(', ', array_map(fn ($v) => '{{'.$v.'}}', $unknown)));
            }
        };

        return $schema->components([
            Grid::make(4)->schema([
                TextInput::make('key')->label('Cheie')->disabled(),
                Select::make('channel')->label('Canal')->options(['email' => 'E-mail', 'sms' => 'SMS'])->disabled(),
                Select::make('locale')->label('Limbă')->options(['ro' => 'Română', 'en' => 'English', 'hu' => 'Magyar'])->disabled(),
                Toggle::make('is_active')->label('Activ')->inline(false),
            ]),
            TextInput::make('subject')->label('Subiect')->maxLength(180)->rule($variables)->visible(fn (Get $get) => $get('channel') === 'email'),
            Textarea::make('body')->label('Mesaj (Markdown simplu pentru e-mail; text pentru SMS)')->required()->rows(12)->rule($variables)
                ->hint(fn (Get $get) => $get('channel') === 'sms' ? mb_strlen((string) $get('body')).' caractere' : null)->live(onBlur: true),
            Text::make(new HtmlString('<strong>Variabile disponibile:</strong> '.collect(TemplateRenderer::VARIABLES)->map(fn ($label, $key) => '<code>{{'.e($key).'}}</code> '.e($label))->implode(' · '))),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('key')
            ->columns([
                TextColumn::make('key')->label('Mesaj')->searchable()->fontFamily('mono'),
                TextColumn::make('channel')->label('Canal')->badge(),
                TextColumn::make('locale')->label('Limbă'),
                TextColumn::make('subject')->label('Subiect')->limit(60)->placeholder('—'),
                ToggleColumn::make('is_active')->label('Activ'),
            ])
            ->filters([SelectFilter::make('channel')->options(['email' => 'E-mail', 'sms' => 'SMS'])])
            ->recordActions([
                EditAction::make(),
                Action::make('test')->label('Trimite test')->icon('heroicon-o-paper-airplane')->color('gray')
                    ->schema([TextInput::make('to')->label('Destinatar de test (adresă din lista de test)')->required()->default(fn () => auth()->user()?->email)])
                    ->action(function (NotificationTemplate $record, array $data) {
                        $log = app(Notifier::class)->sendToAddress($record->channel, $data['to'], $record->key, ['__sample' => true], 'service', 'template-test:'.$record->id.':'.now()->format('YmdHis'));
                        Notification::make()->success()->title($log ? 'Mesajul de test a fost pus în coadă (status: '.$log->status.').' : 'Mesajul nu a fost trimis.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageNotificationTemplates::route('/')];
    }
}
