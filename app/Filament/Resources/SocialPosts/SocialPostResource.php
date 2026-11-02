<?php

namespace App\Filament\Resources\SocialPosts;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Cms\Models\Media;
use App\Modules\Marketing\Models\SocialPost;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SocialPostResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = SocialPost::class;

    protected static string $viewPermission = 'social.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Social media';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $modelLabel = 'postare';

    protected static ?string $pluralModelLabel = 'Toate postările';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(4)->schema([
                DatePicker::make('publish_on')->label('Data')->required()->native(false)->displayFormat('d.m.Y'),
                TextInput::make('publish_time')->label('Ora sugerată')->placeholder('18:00')->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->maxLength(5),
                Select::make('format')->label('Format')->options(SocialPost::FORMATS)->default('post')->required(),
                Select::make('theme')->label('Temă')->options(SocialPost::THEMES)->required(),
            ]),
            CheckboxList::make('channels')->label('Unde se publică')->options(SocialPost::CHANNELS)->columns(4)->required()->default(['instagram', 'facebook']),
            TextInput::make('title')->label('Titlu intern (nu se publică)')->required()->maxLength(160),
            Textarea::make('caption')->label('Textul postării')->required()->rows(9)->maxLength(2200)
                ->helperText('Instagram acceptă cel mult 2.200 de caractere. Linkurile din text nu sunt clicabile pe Instagram: puneți linkul în bio.'),
            TextInput::make('hashtags')->label('Hashtag-uri')->maxLength(500)->placeholder('#AnotherWorldCluj #RealitateVirtuala'),
            Select::make('media_ids')->label('Imagini (în ordinea din postare)')->multiple()->searchable()->allowHtml()->maxItems(10)
                ->getSearchResultsUsing(fn (string $search) => self::mediaQuery()
                    ->where(fn ($q) => $q->where('static_key', 'like', "%{$search}%")->orWhere('alt', 'like', "%{$search}%")->orWhere('tags', 'like', "%{$search}%"))
                    ->limit(40)->get()->mapWithKeys(fn (Media $m) => [$m->id => self::mediaOption($m)])->all())
                ->options(fn () => self::mediaQuery()->latest('id')->limit(40)->get()->mapWithKeys(fn (Media $m) => [$m->id => self::mediaOption($m)])->all())
                ->getOptionLabelsUsing(fn (array $values) => Media::query()->whereIn('id', $values)->get()->mapWithKeys(fn (Media $m) => [$m->id => self::mediaOption($m)])->all())
                ->helperText('Căutați după descriere sau etichetă: copii, prieteni, companii, logo, jocuri, echipament…'),
            Section::make('Publicare')->collapsed()->schema([
                Grid::make(2)->schema([
                    Select::make('status')->label('Stare')->options(SocialPost::STATUSES)->default('planned')->required(),
                    TextInput::make('published_url')->label('Link către postarea publicată')->url()->maxLength(500),
                ]),
                Textarea::make('notes')->label('Note interne')->rows(2)->maxLength(2000),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('publish_on')
            ->columns([
                ImageColumn::make('thumb')->label('')->state(fn (SocialPost $record) => ($m = $record->media()->first()) ? url($m->urlForWidth(480, ['jpg', 'webp'])) : null)->square()->imageSize(56),
                TextColumn::make('publish_on')->label('Data')->date('D, d.m.Y')->description(fn (SocialPost $record) => $record->publish_time ? 'ora '.$record->publish_time : null)->sortable(),
                TextColumn::make('title')->label('Postare')->description(fn (SocialPost $record) => str($record->caption)->limit(90))->wrap()->searchable(['title', 'caption']),
                TextColumn::make('theme')->label('Temă')->formatStateUsing(fn ($state) => SocialPost::THEMES[$state] ?? $state)->badge()->color('gray')->toggleable(),
                TextColumn::make('channels')->label('Canale')->formatStateUsing(fn ($state) => SocialPost::CHANNELS[$state] ?? $state)->badge()->color('info'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => SocialPost::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'ready' => 'info', 'skipped' => 'gray', default => 'warning'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stare')->options(SocialPost::STATUSES),
                SelectFilter::make('theme')->label('Temă')->options(SocialPost::THEMES),
                SelectFilter::make('period')->label('Perioadă')->default('upcoming')
                    ->options(['upcoming' => 'De azi încolo', 'past' => 'Trecute', 'all' => 'Toate'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'upcoming') {
                        'upcoming' => $query->whereDate('publish_on', '>=', today()),
                        'past' => $query->whereDate('publish_on', '<', today()),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('bundle')->label('Descarcă')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->url(fn (SocialPost $record) => route('admin.social.bundle', $record)),
                EditAction::make(),
                ActionGroup::make([
                    self::markPublishedAction(),
                    Action::make('skip')->label('Marchează sărită')->icon('heroicon-o-forward')->color('gray')
                        ->visible(fn (SocialPost $record) => $record->status !== 'skipped')
                        ->action(fn (SocialPost $record) => $record->update(['status' => 'skipped'])),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function markPublishedAction(): Action
    {
        return Action::make('markPublished')->label('Marchează publicată')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (SocialPost $record) => $record->status !== 'published')
            ->schema([TextInput::make('published_url')->label('Link către postare (opțional)')->url()->maxLength(500)])
            ->action(function (SocialPost $record, array $data) {
                $record->update(['status' => 'published', 'published_at' => now(), 'published_by' => auth()->id(), 'published_url' => $data['published_url'] ?? null]);
                Notification::make()->success()->title('Postarea a fost marcată publicată.')->send();
            });
    }

    /** Imaginile care pot fi folosite: doar cele publicabile (drepturi cunoscute, acord pentru minori). */
    public static function mediaQuery(): Builder
    {
        return Media::query()->where('kind', 'image')->where('rights_status', '!=', 'unknown')
            ->where(fn ($q) => $q->where('depicts_minors', false)->orWhere('guardian_consent_confirmed', true));
    }

    public static function mediaOption(Media $media): string
    {
        $thumb = e($media->urlForWidth(480, ['jpg', 'webp']));
        $alt = e(str($media->altText() ?: ($media->static_key ?? 'imagine'))->limit(70));
        $tags = e(implode(', ', (array) $media->tags));

        return "<span style=\"display:flex;gap:.5rem;align-items:center\"><img src=\"{$thumb}\" alt=\"\" style=\"width:48px;height:36px;object-fit:cover;border-radius:4px;flex:none\"><span>#{$media->id} · {$alt}".($tags ? " <small style=\"opacity:.7\">({$tags})</small>" : '').'</span></span>';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialPosts::route('/'),
            'create' => Pages\CreateSocialPost::route('/create'),
            'edit' => Pages\EditSocialPost::route('/{record}/edit'),
        ];
    }
}
