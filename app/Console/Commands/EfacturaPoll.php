<?php

namespace App\Console\Commands;

use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Finance\Efactura\AnafClient;
use App\Modules\Finance\Efactura\EfacturaService;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aw:efactura-poll')]
#[Description('Verifică starea facturilor trimise la ANAF și, dacă e activat, transmite automat facturile validate')]
class EfacturaPoll extends Command
{
    public function handle(EfacturaService $efactura, AnafClient $anaf, SettingsRepository $settings): int
    {
        if (! $anaf->isAuthorized()) {
            $this->line('e-Factura neconfigurată (fără autorizare ANAF) — nimic de făcut.');

            return self::SUCCESS;
        }
        if ($settings->bool('finance.efactura_auto_upload')) {
            foreach (Invoice::query()->where('status', 'issued')->where('efactura_status', 'pending')->limit(20)->get() as $invoice) {
                try {
                    $submission = $efactura->generate($invoice);
                    if ($submission->status === 'validated') {
                        $efactura->upload($submission);
                    }
                } catch (\RuntimeException $e) {
                    $this->warn("{$invoice->full_number}: {$e->getMessage()}");
                }
            }
        }
        $this->info($efactura->poll().' transmiteri verificate.');

        return self::SUCCESS;
    }
}
