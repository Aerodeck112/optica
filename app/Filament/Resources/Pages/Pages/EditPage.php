<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageEditor;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * „Salvează ciorna” nu modifică site-ul. Doar „Publică” (sau publicarea programată) înlocuiește versiunea live.
 */
class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    public string $previewLocale = 'ro';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge($data, app(PageEditor::class)->formState($this->getRecord()));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Page $record */
        $settings = ['og_image_id' => $data['og_image_id'] ?? null, 'noindex' => (bool) ($data['noindex'] ?? false)];
        if (! $record->is_system && isset($data['key'])) {
            $settings['key'] = $data['key'];
        }
        $record->forceFill($settings)->save();

        return app(PageEditor::class)->saveDraft($record, $data, auth()->id());
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Salvează ciorna');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Ciorna a fost salvată. Site-ul nu s-a schimbat încă — folosește „Publică”.';
    }

    public function getSubheading(): ?string
    {
        $page = $this->getRecord();
        $state = PageResource::STATUSES[$page->status] ?? $page->status;
        $draft = $page->has_draft ? ' · există modificări nepublicate' : '';
        $scheduled = $page->scheduled_at ? ' · publicare programată: '.$page->scheduled_at->timezone(config('aw.timezone'))->format('d.m.Y H:i') : '';

        return $state.$draft.$scheduled;
    }

    protected function getHeaderActions(): array
    {
        $canPublish = fn () => auth()->user()?->can('cms.publish');

        return [
            Action::make('preview')
                ->label('Previzualizare')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Previzualizare ciornă')
                ->modalWidth('screen')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Închide')
                ->mountUsing(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                })
                ->modalContent(fn () => view('filament.cms.preview', [
                    'urls' => collect(Locales::codes())->mapWithKeys(fn ($l) => [$l => URL::temporarySignedRoute('cms.preview', now()->addMinutes(30), ['type' => 'page', 'id' => $this->getRecord()->id, 'locale' => $l])])->all(),
                ])),
            Action::make('publish')
                ->label('Publică')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->visible($canPublish)
                ->requiresConfirmation()
                ->modalDescription('Ciorna salvată devine versiunea vizibilă pe site. Modificările nesalvate din formular se salvează înainte.')
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    app(PageEditor::class)->publish($this->getRecord()->refresh(), auth()->id());
                    $this->refreshFormData(['published_locales']);
                    Notification::make()->success()->title('Pagina a fost publicată.')->send();
                }),
            ActionGroup::make([
                Action::make('schedule')
                    ->label('Programează publicarea')
                    ->icon('heroicon-o-clock')
                    ->visible($canPublish)
                    ->schema([
                        DateTimePicker::make('at')->label('Data și ora (ora României)')->seconds(false)->timezone(config('aw.timezone'))->required()->minDate(now()),
                    ])
                    ->action(function (array $data) {
                        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                        app(PageEditor::class)->publish($this->getRecord()->refresh(), auth()->id(), Carbon::parse($data['at']));
                        Notification::make()->success()->title('Publicarea a fost programată.')->send();
                    }),
                Action::make('discard')
                    ->label('Renunță la ciornă')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn () => $this->getRecord()->has_draft && $this->getRecord()->status !== 'draft')
                    ->requiresConfirmation()
                    ->modalDescription('Formularul revine la versiunea publicată. Ciorna rămâne disponibilă în istoricul reviziilor.')
                    ->action(function () {
                        app(PageEditor::class)->discardDraft($this->getRecord(), auth()->id());
                        $this->fillForm();
                        Notification::make()->success()->title('Ciorna a fost eliminată.')->send();
                    }),
                Action::make('view_live')
                    ->label('Vezi pe site')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn () => $this->getRecord()->url('ro'), shouldOpenInNewTab: true)
                    ->visible(fn () => $this->getRecord()->isLive()),
                Action::make('unpublish')
                    ->label('Retrage de pe site')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn () => $canPublish() && $this->getRecord()->status === 'published' && $this->getRecord()->key !== 'home')
                    ->requiresConfirmation()
                    ->action(function () {
                        app(PageEditor::class)->unpublish($this->getRecord(), auth()->id());
                        Notification::make()->success()->title('Pagina nu mai este vizibilă pe site.')->send();
                    }),
                DeleteAction::make()->visible(fn () => ! $this->getRecord()->is_system),
            ])->label('Mai multe')->icon('heroicon-o-ellipsis-vertical')->button()->color('gray'),
        ];
    }
}
