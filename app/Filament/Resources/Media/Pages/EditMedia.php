<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Modules\Audit\AuditLogger;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Services\MediaProcessor;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMedia extends EditRecord
{
    protected static string $resource = MediaResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Media $record */
        if (! empty($data['guardian_consent_confirmed']) && ! $record->guardian_consent_confirmed) {
            $data['guardian_consent_confirmed_by'] = auth()->id();
            app(AuditLogger::class)->log('media.guardian_consent_confirmed', $record);
        }
        if (empty($data['depicts_minors'])) {
            $data['guardian_consent_confirmed'] = false;
        }
        unset($data['kind']);
        $record->update($data);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label('Deschide originalul')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->url(fn () => $this->getRecord()->originalUrl(), shouldOpenInNewTab: true),
            Action::make('regenerate')->label('Regenerează variantele')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => $this->getRecord()->isImage() && ! $this->getRecord()->static_key)
                ->action(function () {
                    app(MediaProcessor::class)->generateVariants($this->getRecord());
                    Notification::make()->success()->title('Variantele au fost regenerate.')->send();
                }),
            DeleteAction::make()
                ->before(function (DeleteAction $action) {
                    $media = $this->getRecord();
                    if ($media->usages()->exists()) {
                        Notification::make()->danger()->title('Fișierul este folosit și nu poate fi șters.')
                            ->body('Înlocuiește-l mai întâi în: '.MediaResource::usageText($media))->persistent()->send();
                        $action->cancel();
                    }
                })
                ->after(fn () => app(MediaProcessor::class)->deleteFiles($this->getRecord()))
                ->modalDescription('Ștergerea este verificată: fișierele folosite în pagini, jocuri, pachete sau articole nu pot fi șterse.'),
        ];
    }
}
