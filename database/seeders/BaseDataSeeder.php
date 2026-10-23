<?php

namespace Database\Seeders;

use App\Modules\Booking\Models\Resource;
use App\Modules\Catalog\Models\VatRate;
use App\Modules\Core\Models\Integration;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Structuri tehnice de bază (fără date comerciale): cote TVA cu perioade de valabilitate, unități de măsură,
 * integrări în starea „neconfigurată”, resursa principală (arena) cu capacitate necompletată.
 */
class BaseDataSeeder extends Seeder
{
    public function run(): void
    {
        // Cotele TVA din România. Modificarea de la 1 august 2025 (Legea nr. 141/2025): 19% → 21%, 9% → 11%.
        // Încadrarea serviciilor arenei pe grupe se confirmă de contabil.
        $rates = [
            ['TVA standard 19%', 19, 'S', 'standard', '2017-01-01', '2025-07-31'],
            ['TVA standard 21%', 21, 'S', 'standard', '2025-08-01', null],
            ['TVA redusă 9%', 9, 'S', 'reduced', '2017-01-01', '2025-07-31'],
            ['TVA redusă 11%', 11, 'S', 'reduced', '2025-08-01', null],
            ['Neplătitor de TVA / scutit', 0, 'O', 'exempt', '2017-01-01', null],
        ];
        foreach ($rates as [$name, $rate, $code, $group, $from, $until]) {
            VatRate::query()->updateOrCreate(['group' => $group, 'valid_from' => $from], [
                'name' => $name, 'rate' => $rate, 'category_code' => $code, 'valid_until' => $until,
                'exemption_reason' => $code === 'O' ? 'Furnizor neînregistrat în scopuri de TVA' : null,
                'needs_accountant_review' => true,
                'notes' => 'Verificați cu contabilul cota aplicabilă serviciilor de agrement și regimul de TVA al firmei.',
            ]);
        }

        foreach ([['H87', 'Bucată', 'buc'], ['C62', 'Unitate', 'unit.'], ['SET', 'Set', 'set'], ['KGM', 'Kilogram', 'kg'], ['LTR', 'Litru', 'l'], ['HUR', 'Oră', 'h'], ['E48', 'Serviciu', 'serv.']] as [$code, $name, $symbol]) {
            Unit::query()->updateOrCreate(['code' => $code], ['name' => $name, 'symbol' => $symbol]);
        }

        foreach ([['ECH', 'Echipamente', 'equipment'], ['CONS', 'Consumabile', 'consumables'], ['MARF', 'Mărfuri', 'merchandise']] as [$code, $name, $type]) {
            Warehouse::query()->updateOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
        }

        foreach (['email' => 'E-mail', 'sms' => 'SMS', 'payments' => 'Plăți online', 'ai' => 'Asistent AI', 'efactura' => 'RO e-Factura (ANAF)', 'fiscal' => 'Casă de marcat'] as $key => $label) {
            Integration::query()->firstOrCreate(['key' => $key], ['mode' => 'disabled', 'status' => 'unconfigured', 'options' => ['label' => $label]]);
        }

        Resource::query()->firstOrCreate(['type' => 'arena', 'parent_id' => null], [
            'name' => 'Arena',
            'capacity' => null, // de completat: numărul maxim de jucători simultan
            'is_active' => true,
            'is_public' => true,
        ]);
    }
}
