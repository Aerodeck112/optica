<?php

namespace App\Filament\Cms;

use App\Filament\Support\Fields;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Blocks\BlockRegistry;
use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Models\GalleryItem;
use App\Modules\Cms\Models\Media;
use App\Modules\Crm\Models\Lead;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * Formularele blocurilor din editorul de pagini. Lista de blocuri este închisă (BlockRegistry);
 * textele sunt simple sau Markdown fără HTML, linkurile sunt validate, imaginile vin din biblioteca media.
 */
final class BlockForms
{
    public static function builder(string $name): Builder
    {
        return Builder::make($name)
            ->label('Blocuri')
            ->blocks(self::blocks())
            ->blockNumbers(false)
            ->collapsible()
            ->cloneable()
            ->reorderableWithButtons()
            ->addActionLabel('Adaugă bloc')
            ->blockPickerColumns(3)
            ->columnSpanFull();
    }

    /** @return array<int, Block> */
    public static function blocks(): array
    {
        $make = fn (string $type, array $schema) => Block::make($type)
            ->label(fn (?array $state) => BlockRegistry::label($type).(($state['hidden'] ?? false) ? ' (ascuns)' : '').(! empty($state['heading']) ? ' — '.str($state['heading'])->limit(40) : (! empty($state['title_1']) ? ' — '.$state['title_1'] : '')))
            ->icon(BlockRegistry::BLOCKS[$type]['icon'])
            ->schema([...$schema, self::options()]);

        return [
            $make('hero_portal', [
                Grid::make(2)->schema([
                    Fields::text('location', 'Linie locație (ex.: oraș)', 80),
                    Fields::text('title_1', 'Titlu — rândul 1', 60)->required(),
                    Fields::text('title_2', 'Titlu — rândul 2', 60),
                    Textarea::make('lede')->label('Text introductiv')->rows(2)->maxLength(300),
                ]),
                self::cta('primary', 'Buton principal'),
                self::cta('secondary', 'Buton secundar'),
                self::cta('aside', 'Link lateral (ex.: aniversări)'),
                Fields::media('poster_image', 'Imagine poster (afișată înainte/în locul video)'),
                Repeater::make('video_sources')->label('Surse video (fișiere din /media/video)')
                    ->schema([
                        TextInput::make('src')->label('Cale')->required()->regex('/^\/media\/video\/[a-z0-9\-_.\/]+\.(mp4|webm)$/i')->placeholder('/media/video/portal-loop.mp4'),
                        TextInput::make('type')->label('Tip MIME')->default('video/mp4')->maxLength(80),
                    ])->columns(2)->defaultItems(0)->reorderable(),
            ]),
            $make('statement', [
                Textarea::make('text')->label('Mesaj')->required()->rows(2)->maxLength(240),
                MarkdownEditor::make('body')->label('Text suplimentar')->toolbarButtons(self::mdToolbar())->maxLength(2000),
            ]),
            $make('audience_paths', [
                ...self::headingIntro(),
                Repeater::make('items')->label('Categorii')->schema([
                    Fields::text('title', 'Titlu', 80)->required(),
                    Textarea::make('text')->label('Descriere')->rows(2)->maxLength(240),
                    Fields::media('image', 'Imagine'),
                    Fields::link('link'),
                    Fields::text('link_label', 'Text link', 40),
                ])->columns(2)->collapsible()->itemLabel(fn (array $state) => $state['title'] ?? null)->maxItems(6),
            ]),
            $make('game_rail', [
                ...self::headingIntro(),
                Grid::make(3)->schema([
                    Select::make('source')->label('Ce jocuri')->options(['featured' => 'Recomandate primele', 'all' => 'Toate, în ordinea din catalog'])->default('featured'),
                    TextInput::make('limit')->label('Câte jocuri')->numeric()->minValue(1)->maxValue(24)->default(8),
                    Fields::text('cta_label', 'Text buton „toate jocurile”', 40),
                ]),
            ]),
            $make('feature_split', [
                Fields::text('heading', 'Titlu', 120)->required(),
                MarkdownEditor::make('body')->label('Text')->toolbarButtons(self::mdToolbar())->maxLength(3000),
                Repeater::make('bullets')->label('Puncte cheie')->simple(TextInput::make('text')->maxLength(140))->defaultItems(0)->maxItems(6),
                Grid::make(3)->schema([
                    Fields::media('image', 'Imagine'),
                    Select::make('image_side')->label('Poziție imagine')->options(['left' => 'Stânga', 'right' => 'Dreapta'])->default('left'),
                    Select::make('image_shape')->label('Format imagine')->options(['portrait' => 'Portret', 'wide' => 'Lat', 'square' => 'Pătrat'])->default('portrait'),
                    Select::make('tone')->label('Ton vizual')->options(['default' => 'Implicit (violet)', 'birthday' => 'Aniversare (magenta)', 'corporate' => 'Companii (cyan)'])->default('default'),
                ]),
                self::cta('primary', 'Buton principal'),
                self::cta('secondary', 'Buton secundar'),
            ]),
            $make('steps', [
                ...self::headingIntro(),
                Repeater::make('steps')->label('Pași (în ordine)')->schema([
                    Fields::text('title', 'Pas', 80)->required(),
                    Textarea::make('text')->label('Detalii')->rows(2)->maxLength(240),
                ])->columns(2)->minItems(1)->maxItems(8)->itemLabel(fn (array $state) => $state['title'] ?? null),
            ]),
            $make('image_band', [
                Fields::media('image', 'Imagine')->required(),
                Fields::text('text', 'Text peste imagine', 160),
            ]),
            $make('gallery_mosaic', [
                ...self::headingIntro(),
                Grid::make(2)->schema([
                    Select::make('album')->label('Album')->options(fn () => ['all' => 'Toate'] + GalleryItem::query()->distinct()->pluck('album', 'album')->all())->default('all'),
                    TextInput::make('limit')->label('Număr maxim imagini')->numeric()->minValue(1)->maxValue(48)->default(12),
                ]),
                Select::make('images')->label('Sau alege imagini anume (opțional)')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Media::query()->where('kind', 'image')->where(fn ($q) => $q->where('static_key', 'like', "%{$search}%")->orWhere('original_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($m) => [$m->id => Fields::mediaLabel($m)])->all())
                    ->getOptionLabelsUsing(fn (array $values) => Media::query()->whereIn('id', $values)->get()->mapWithKeys(fn ($m) => [$m->id => Fields::mediaLabel($m)])->all()),
            ]),
            $make('testimonials', [
                Fields::text('heading', 'Titlu', 120),
                TextInput::make('limit')->label('Câte recenzii')->numeric()->minValue(1)->maxValue(12)->default(6)
                    ->helperText('Se afișează doar recenziile reale, cu acord de publicare, marcate „Publicat”. Fără recenzii, blocul nu apare.'),
            ]),
            $make('faq_list', [
                Fields::text('heading', 'Titlu', 120),
                Grid::make(3)->schema([
                    Select::make('category')->label('Categorie')->options(fn () => ['all' => 'Toate'] + Faq::query()->distinct()->pluck('category', 'category')->all())->default('all'),
                    TextInput::make('limit')->label('Număr maxim')->numeric()->minValue(1)->maxValue(100)->default(50),
                    Toggle::make('grouped')->label('Grupează pe categorii')->inline(false),
                ]),
            ]),
            $make('packages_table', [
                ...self::headingIntro(),
                Select::make('type')->label('Tip pachete')->options(['all' => 'Toate'] + Package::TYPES)->default('all'),
                self::cta('empty_cta', 'Buton când nu există pachete configurate', 'Cere ofertă'),
            ]),
            $make('lead_form', [
                Select::make('type')->label('Tip cerere')->options(Lead::TYPES)->required()->default('birthday'),
                ...self::headingIntro(),
            ]),
            $make('contact_details', self::headingIntro()),
            $make('contact_form', self::headingIntro()),
            $make('notice', [
                Fields::text('heading', 'Titlu', 120),
                MarkdownEditor::make('body')->label('Text')->required()->toolbarButtons(self::mdToolbar())->maxLength(3000),
                Select::make('tone')->label('Tip')->options(['info' => 'Informare', 'warning' => 'Atenționare', 'success' => 'Confirmare'])->default('info'),
            ]),
            $make('rich_text', [
                Fields::text('heading', 'Titlu', 120),
                MarkdownEditor::make('body')->label('Text')->required()->toolbarButtons(self::mdToolbar())->maxLength(20000),
                Select::make('width')->label('Lățime')->options(['narrow' => 'Îngustă (lectură)', 'wide' => 'Lată'])->default('narrow'),
            ]),
            $make('cta_portal', [
                Fields::text('heading', 'Titlu', 120),
                Textarea::make('text')->label('Text')->rows(2)->maxLength(300),
                self::cta('primary', 'Buton principal'),
                self::cta('secondary', 'Buton secundar'),
            ]),
            $make('news_list', [
                Fields::text('heading', 'Titlu', 120),
                TextInput::make('limit')->label('Câte articole')->numeric()->minValue(1)->maxValue(12)->default(3),
            ]),
        ];
    }

    private static function headingIntro(): array
    {
        return [
            Fields::text('heading', 'Titlu', 120),
            Textarea::make('intro')->label('Introducere')->rows(2)->maxLength(500),
        ];
    }

    private static function cta(string $prefix, string $label, ?string $placeholder = null): Fieldset
    {
        return Fieldset::make($label)->schema([
            Fields::text("{$prefix}_label", 'Text', 50)->placeholder($placeholder),
            Fields::link("{$prefix}_link"),
        ])->columns(2);
    }

    private static function options(): Section
    {
        return Section::make('Opțiuni bloc')->collapsed()->compact()->schema([
            Toggle::make('hidden')->label('Ascuns pe site')->helperText('Blocul rămâne în pagină, dar nu se afișează.'),
            TextInput::make('anchor')->label('Ancoră (id)')->regex('/^[a-z][a-z0-9\-]*$/')->maxLength(40)->helperText('Pentru linkuri de tip #ancora.'),
        ])->columns(2);
    }

    private static function mdToolbar(): array
    {
        return [['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']];
    }
}
