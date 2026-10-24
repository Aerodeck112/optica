<?php

namespace App\Filament\Resources\Invoices;

use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Finance\Efactura\AnafClient;
use App\Modules\Finance\Efactura\EfacturaService;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use RuntimeException;

/** Emitere, stornare și e-Factura. Regulile (numerotare, înghețare, transmitere unică) sunt în servicii. */
final class InvoiceActions
{
    private static function run(Closure $callback, string $success): mixed
    {
        try {
            $result = $callback();
            Notification::make()->success()->title($success)->send();

            return $result;
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return null;
        }
    }

    public static function issue(): Action
    {
        return Action::make('issue')->label('Emite factura')->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (Invoice $record) => $record->status === 'draft' && auth()->user()?->can('finance.issue'))
            ->requiresConfirmation()
            ->modalHeading('Emite factura')
            ->modalDescription('Se alocă următorul număr din serie, iar documentul nu mai poate fi modificat. Corecțiile ulterioare se fac doar prin stornare.')
            ->action(fn (Invoice $record) => self::run(fn () => app(InvoiceService::class)->issue($record, auth()->id()), 'Factura a fost emisă.'));
    }

    public static function pdf(): Action
    {
        return Action::make('pdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (Invoice $record) => $record->status === 'issued')
            ->url(fn (Invoice $record) => route('admin.invoices.pdf', $record), shouldOpenInNewTab: true);
    }

    public static function storno(): Action
    {
        return Action::make('storno')->label('Stornează')->icon('heroicon-o-arrow-uturn-left')->color('danger')
            ->visible(fn (Invoice $record) => $record->status === 'issued' && $record->document_type === 'invoice' && auth()->user()?->can('finance.issue')
                && ! Invoice::query()->where('related_invoice_id', $record->id)->where('document_type', 'credit_note')->where('status', 'issued')->exists())
            ->modalDescription('Se emite imediat o factură de stornare cu valori negative, cu următorul număr din serie. Factura originală rămâne neschimbată.')
            ->schema([TextInput::make('reason')->label('Motiv')->required()->maxLength(200)])
            ->action(fn (Invoice $record, array $data, $livewire) => ($credit = self::run(fn () => app(InvoiceService::class)->storno($record, $data['reason'], auth()->id()), 'Factura de stornare a fost emisă.'))
                ? $livewire->redirect(InvoiceResource::getUrl('view', ['record' => $credit])) : null);
    }

    public static function efactura(): Action
    {
        return Action::make('efactura')->label(fn (Invoice $record) => in_array($record->efactura_status, ['pending', 'error', 'rejected'], true) ? 'Generează și transmite e-Factura' : 'Transmite e-Factura')
            ->icon('heroicon-o-paper-airplane')->color('primary')
            ->visible(fn (Invoice $record) => $record->status === 'issued' && in_array($record->efactura_status, ['pending', 'validated', 'error', 'rejected'], true) && auth()->user()?->can('efactura.manage'))
            ->requiresConfirmation()
            ->modalDescription(function (Invoice $record) {
                $env = app(AnafClient::class)->environment() === 'prod' ? 'PRODUCȚIE' : 'TEST (fără efect fiscal)';
                $text = "XML-ul UBL / CIUS-RO se generează și se validează local, apoi se trimite la ANAF — mediul {$env}. Termen legal: ".app(EfacturaService::class)->deadline($record)->format('d.m.Y').'.';
                if ($record->efactura_status === 'error') {
                    $text .= ' Atenție: ultima transmitere a avut rezultat incert. Verificați în SPV că factura nu a ajuns deja la ANAF înainte de a continua.';
                }

                return $text;
            })
            ->action(function (Invoice $record) {
                $service = app(EfacturaService::class);
                self::run(function () use ($service, $record) {
                    $submission = $service->generate($record, auth()->id());
                    if ($submission->status !== 'validated') {
                        throw new RuntimeException('Validarea locală a găsit erori: '.implode(' ', array_slice((array) $submission->validation_errors, 0, 3)));
                    }

                    return $service->upload($submission);
                }, 'Factura a fost transmisă. Starea se actualizează automat la câteva minute.');
            });
    }

    public static function exempt(): Action
    {
        return Action::make('efactura_exempt')->label('Nu se transmite')->icon('heroicon-o-no-symbol')->color('gray')
            ->visible(fn (Invoice $record) => $record->status === 'issued' && in_array($record->efactura_status, ['pending', 'validated'], true) && auth()->user()?->can('efactura.manage'))
            ->requiresConfirmation()
            ->modalDescription('Folosiți doar dacă documentul nu intră sub obligația de raportare (de ex. B2C anterior obligației sau cumpărător extern). Decizia se consemnează în jurnalul de audit.')
            ->schema([TextInput::make('reason')->label('Motiv')->required()->maxLength(200)])
            ->action(function (Invoice $record, array $data) {
                $record->forceFill(['efactura_status' => 'not_applicable'])->save();
                app(AuditLogger::class)->log('efactura.marked_not_applicable', $record, ['reason' => $data['reason']]);
                Notification::make()->success()->title('Marcată ca netransmisibilă.')->send();
            });
    }

    /** Din rezervare: ciornă de factură (finală sau de avans), apoi deschide editarea. */
    public static function fromBooking(): Action
    {
        return Action::make('invoice_booking')->label('Factură')->icon('heroicon-o-document-plus')->color('gray')
            ->visible(fn (Booking $record) => auth()->user()?->can('finance.issue') && $record->total_minor !== null && ! in_array($record->status, ['request', 'expired'], true))
            ->schema(fn (Booking $record) => [
                Select::make('kind')->label('Tip')->native()->required()->default($record->status === 'completed' ? 'standard' : 'advance')
                    ->options(['standard' => 'Factură pentru serviciu (regularizează avansurile facturate)', 'advance' => 'Factură de avans']),
            ])
            ->modalDescription('Se creează o ciornă; o puteți verifica și modifica înainte de emitere.')
            ->action(function (Booking $record, array $data, $livewire) {
                $draft = self::run(fn () => app(InvoiceService::class)->draftForBooking($record->load('items', 'customer', 'package'), $data['kind'], auth()->id()), 'Ciorna a fost creată.');
                if ($draft) {
                    $livewire->redirect(InvoiceResource::getUrl('edit', ['record' => $draft]));
                }
            });
    }
}
