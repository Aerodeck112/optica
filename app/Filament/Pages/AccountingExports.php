<?php

namespace App\Filament\Pages;

use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Services\AccountingExport;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exporturi lunare pentru contabil. */
class AccountingExports extends Page
{
    protected string $view = 'filament.pages.accounting-exports';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-on-square-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Financiar';

    protected static ?string $navigationLabel = 'Export contabilitate';

    protected static ?string $title = 'Export pentru contabilitate';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('finance.export');
    }

    public function mount(): void
    {
        $previous = now(config('aw.timezone'))->subMonthNoOverflow();
        $this->form->fill(['from' => $previous->startOfMonth()->toDateString(), 'to' => $previous->endOfMonth()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Perioada')->description('Implicit: luna anterioară. Datele exportate sunt cele emise; ciornele nu apar.')->columns(2)->schema([
                DatePicker::make('from')->label('De la')->required()->native(false)->displayFormat('d.m.Y'),
                DatePicker::make('to')->label('Până la')->required()->native(false)->displayFormat('d.m.Y')->afterOrEqual('from'),
            ]),
        ])->statePath('data');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(): array
    {
        $state = $this->form->getState();

        return [CarbonImmutable::parse($state['from']), CarbonImmutable::parse($state['to'])];
    }

    private function csvAction(string $type): Action
    {
        return Action::make($type.'Csv')->label(AccountingExport::TYPES[$type].' (CSV)')->icon('heroicon-o-table-cells')->color('gray')
            ->action(function () use ($type): StreamedResponse {
                [$from, $to] = $this->period();
                $content = app(AccountingExport::class)->csv($type, $from, $to);
                app(AuditLogger::class)->log('finance.exported', null, ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

                return response()->streamDownload(fn () => print ($content), "{$type}-{$from->format('Ymd')}-{$to->format('Ymd')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }

    public function invoicesCsvAction(): Action
    {
        return $this->csvAction('invoices');
    }

    public function paymentsCsvAction(): Action
    {
        return $this->csvAction('payments');
    }

    public function purchasesCsvAction(): Action
    {
        return $this->csvAction('purchases');
    }

    public function documentsZipAction(): Action
    {
        return Action::make('documentsZip')->label('PDF facturi + XML e-Factura (ZIP)')->icon('heroicon-o-archive-box-arrow-down')->color('gray')
            ->action(function (): StreamedResponse {
                [$from, $to] = $this->period();
                $path = app(AccountingExport::class)->documentsZip($from, $to);
                app(AuditLogger::class)->log('finance.exported', null, ['type' => 'documents', 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

                return Storage::disk('local')->download($path);
            });
    }
}
