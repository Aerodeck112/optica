<?php

namespace App\Filament\Resources\DisplayPlaylists;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Catalog\Models\Game;
use App\Modules\Display\Models\DisplayAsset;
use App\Modules\Display\Models\DisplayPlaylist;
use App\Modules\Display\Models\DisplayPlaylistItem;
use App\Modules\Display\Models\DisplayScreen;
use App\Modules\Display\Services\PlaylistPreview;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Playlisturile ecranelor: secvențe ordonate prin drag-and-drop, cu durate, ponderi și programare. */
class DisplayPlaylistResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = DisplayPlaylist::class;

    protected static string $viewPermission = 'display.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Ecrane afișare';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $modelLabel = 'playlist';

    protected static ?string $pluralModelLabel = 'Playlisturi';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Playlist')->schema([
                Grid::make(4)->schema([
                    TextInput::make('name')->label('Denumire')->required()->maxLength(120),
                    Select::make('mode')->label('Mod')->options(DisplayPlaylist::MODES)->required()->default('smart'),
                    TextInput::make('min_main_seconds')->label('Durata minimă a conținutului principal (s)')->numeric()->minValue(15)->maxValue(600)->default(45)->required(),
                    TextInput::make('max_consecutive_ads')->label('Maximum reclame la rând')->numeric()->minValue(1)->maxValue(5)->default(2)->required(),
                ]),
                Toggle::make('is_active')->label('Activ')->default(true),
                Text::make('Aleatoriu controlat: după conținutul principal rulează 1–N materiale alese după pondere, fără același material de două ori la rând (când există alternative). Materialele expirate sau dezactivate se sar; dacă nu există reclame, ecranul alternează conținutul principal cu logo și ceas.'),
            ]),
            Section::make('Secvențe (trage pentru a reordona)')->schema([
                Repeater::make('items')->relationship()->orderColumn('sort')->reorderable()->reorderableWithDragAndDrop()->collapsible()
                    ->itemLabel(fn (array $state) => (DisplayPlaylistItem::TYPES[$state['type'] ?? ''] ?? 'Secvență').' · '.($state['duration_seconds'] ?? '?').' s'.(($state['is_active'] ?? true) ? '' : ' · inactivă'))
                    ->schema([
                        Grid::make(4)->schema([
                            Select::make('type')->label('Tip')->options(DisplayPlaylistItem::TYPES)->required()->live(),
                            TextInput::make('duration_seconds')->label('Durată (s)')->numeric()->minValue(3)->maxValue(600)->required()->default(8),
                            TextInput::make('weight')->label('Pondere (1–10)')->numeric()->minValue(1)->maxValue(10)->default(5)->required(),
                            Toggle::make('is_active')->label('Activă')->default(true)->inline(false),
                            Select::make('display_asset_id')->label('Material anume (gol = ales automat)')->columnSpan(2)
                                ->options(fn (Get $get) => DisplayAsset::query()->where('kind', $get('type') === 'video' ? 'video' : 'image')->pluck('title', 'id'))
                                ->visible(fn (Get $get) => in_array($get('type'), ['promo', 'video'], true)),
                            Select::make('game_id')->label('Joc anume (gol = prin rotație)')->columnSpan(2)
                                ->options(fn () => Game::query()->orderBy('sort')->get()->mapWithKeys(fn (Game $g) => [$g->id => $g->getTranslation('title', 'ro')]))
                                ->visible(fn (Get $get) => $get('type') === 'game'),
                            TextInput::make('message')->label('Mesaj (max. 200 de caractere)')->maxLength(200)->columnSpan(4)
                                ->visible(fn (Get $get) => $get('type') === 'message')->required(fn (Get $get) => $get('type') === 'message'),
                        ]),
                        Grid::make(4)->schema([
                            DatePicker::make('starts_on')->label('De la data'),
                            DatePicker::make('ends_on')->label('Până la data')->afterOrEqual('starts_on'),
                            TimePicker::make('time_from')->label('Între orele')->seconds(false),
                            TimePicker::make('time_until')->label('și')->seconds(false),
                            CheckboxList::make('weekdays')->label('Zile (gol = toate)')->columns(7)->columnSpan(4)
                                ->options([1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Jo', 5 => 'Vi', 6 => 'Sâ', 7 => 'Du']),
                        ]),
                    ])->defaultItems(0)->addActionLabel('Adaugă secvență'),
            ]),
            Section::make('Previzualizarea rotației (următoarele 10 minute, exemplu)')->visibleOn('edit')->collapsible()->schema([
                Text::make(fn (?DisplayPlaylist $record) => $record ? self::previewHtml($record) : ''),
            ]),
        ])->columns(1);
    }

    private static function previewHtml(DisplayPlaylist $playlist): HtmlString
    {
        $type = DisplayScreen::query()->where('display_playlist_id', $playlist->id)->value('type') ?? 'live_arena';
        $rows = app(PlaylistPreview::class)->simulate($playlist, $type);
        $html = '<ol style="display:grid;gap:.25rem;font-variant-numeric:tabular-nums">';
        foreach ($rows as $row) {
            $html .= '<li><strong>'.e($row['at']).'</strong> — '.e($row['label']).' <span style="opacity:.6">('.e((string) $row['seconds']).' s)</span></li>';
        }

        return new HtmlString($html.'</ol><p style="opacity:.7;margin-top:.5rem">Ordinea reală variază (selecție aleatorie controlată). Pe Live Arena, în timpul jocului reclamele sunt scurtate și oprite în ultimele minute.</p>');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Playlist')->weight('bold'),
            TextColumn::make('mode')->label('Mod')->formatStateUsing(fn ($state) => DisplayPlaylist::MODES[$state] ?? $state),
            TextColumn::make('items_count')->counts('items')->label('Secvențe'),
            TextColumn::make('screens')->label('Ecrane')->state(fn (DisplayPlaylist $p) => DisplayScreen::query()->where('display_playlist_id', $p->id)->pluck('name')->implode(', '))->placeholder('—'),
            ToggleColumn::make('is_active')->label('Activ'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDisplayPlaylists::route('/'),
            'create' => Pages\CreateDisplayPlaylist::route('/create'),
            'edit' => Pages\EditDisplayPlaylist::route('/{record}/edit'),
        ];
    }
}
