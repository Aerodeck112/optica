<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\ConsentService;
use App\Modules\Crm\Services\CustomerDataService;
use App\Modules\Crm\Services\CustomerMergeService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->fullName();
    }

    private function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }

    protected function getHeaderActions(): array
    {
        $active = fn () => ! $this->getRecord()->isAnonymized();

        return [
            EditAction::make()->visible(fn () => $active() && $this->can('customers.manage')),
            ActionGroup::make([
                Action::make('consent')
                    ->label('Înregistrează consimțământ marketing')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn () => $active() && $this->can('customers.manage'))
                    ->schema([
                        Select::make('channel')->label('Canal')->options(['email' => 'E-mail', 'sms' => 'SMS'])->required(),
                        Select::make('action')->label('Acțiune')->options(['grant' => 'Acordare', 'withdraw' => 'Retragere'])->required(),
                        Textarea::make('proof')->label('Dovada (ex. formular semnat la recepție nr., e-mail primit la data…)')->required()->maxLength(500),
                        Checkbox::make('confirmed')->label('Confirm că acordul a fost exprimat explicit de client pentru acest canal')->accepted()->visible(fn ($get) => $get('action') === 'grant'),
                    ])
                    ->action(function (array $data) {
                        $service = app(ConsentService::class);
                        $proof = ['note' => $data['proof'], 'recorded_by_user' => auth()->id()];
                        $data['action'] === 'grant'
                            ? $service->grant($this->getRecord(), $data['channel'], 'staff', $proof, 'user', auth()->id())
                            : $service->withdraw($this->getRecord(), $data['channel'], 'staff', $proof, 'user', auth()->id());
                        Notification::make()->success()->title('Consimțământul a fost înregistrat.')->send();
                    }),
                Action::make('merge')
                    ->label('Unește cu un duplicat')
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->visible(fn () => $active() && $this->can('customers.merge'))
                    ->schema([
                        Select::make('duplicate_id')->label('Clientul duplicat (va fi unit în acesta)')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Customer::query()->whereKeyNot($this->getRecord()->id)->whereNull('merged_into_id')->whereNull('anonymized_at')
                                ->where(fn ($query) => $query->where('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('company_name', 'like', "%{$search}%"))
                                ->limit(20)->get()->mapWithKeys(fn (Customer $c) => [$c->id => "#{$c->id} {$c->fullName()} · ".($c->email ?? $c->phone ?? '—')])->all()),
                    ])
                    ->modalDescription('Rezervările, facturile, mesajele, consimțămintele și punctele duplicatului trec pe acest client. Operațiunea nu poate fi anulată.')
                    ->requiresConfirmation()
                    ->action(function (array $data) {
                        try {
                            app(CustomerMergeService::class)->merge($this->getRecord(), Customer::query()->findOrFail($data['duplicate_id']), auth()->id());
                            Notification::make()->success()->title('Clienții au fost uniți.')->send();
                            $this->redirect(CustomerResource::getUrl('view', ['record' => $this->getRecord()]));
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('export')
                    ->label('Exportă datele (GDPR)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => $this->can('customers.export'))
                    ->requiresConfirmation()
                    ->modalDescription('Se generează un fișier JSON cu toate datele clientului. Descărcarea este înregistrată în jurnalul de audit. Transmite fișierul doar persoanei vizate, după verificarea identității.')
                    ->action(function () {
                        $request = app(CustomerDataService::class)->export($this->getRecord(), auth()->id());

                        return Storage::disk('local')->download($request->export_path);
                    }),
                Action::make('anonymize')
                    ->label('Anonimizează (drept de ștergere)')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn () => $active() && $this->can('customers.export'))
                    ->schema([
                        Textarea::make('reason')->label('Temei / cererea clientului (dată, canal)')->required()->maxLength(1000),
                    ])
                    ->modalDescription(fn () => ($blockers = app(CustomerDataService::class)->blockers($this->getRecord()))
                        ? 'Nu se poate anonimiza încă: '.implode(' ', $blockers)
                        : 'Datele de contact, notele și consimțămintele se elimină ireversibil. Facturile emise se păstrează conform obligației legale de arhivare.')
                    ->requiresConfirmation()
                    ->action(function (array $data) {
                        try {
                            app(CustomerDataService::class)->anonymize($this->getRecord(), auth()->id(), $data['reason']);
                            Notification::make()->success()->title('Clientul a fost anonimizat.')->send();
                            $this->redirect(CustomerResource::getUrl('view', ['record' => $this->getRecord()]));
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        }
                    }),
            ])->label('Acțiuni')->button()->color('gray')->icon('heroicon-o-ellipsis-vertical'),
        ];
    }
}
