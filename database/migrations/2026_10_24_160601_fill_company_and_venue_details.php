<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Datele firmei și ale locației, furnizate de operator (24.10.2026). Se completează doar câmpurile goale:
 * valorile introduse deja în panou (Configurare → Setări) nu sunt suprascrise.
 */
return new class extends Migration
{
    private const VALUES = [
        'company.legal_name' => 'VIRTUAL REALITY ARENA S.R.L.',
        'company.tax_id' => '54556790',
        'company.reg_no' => 'J2026027433003',
        'company.registered_office' => 'Str. Principală nr. 536/J, ap. 2, sat Cristești, com. Cristești',
        'company.registered_city' => 'Cristești',
        'contact.phone_main' => '+40 750 201 277',
        'venue.address_street' => 'Str. Teodor Mihali nr. 31-35',
        'venue.address_city' => 'Cluj-Napoca',
        'venue.maps_url' => 'https://www.google.com/maps/search/?api=1&query=Strada%20Teodor%20Mihali%2031-35%2C%20Cluj-Napoca',
    ];

    public function up(): void
    {
        foreach (self::VALUES as $key => $value) {
            $current = DB::table('settings')->where('key', $key)->value('value');
            if ($current !== null && trim((string) $current) !== '') {
                continue;
            }
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::forget('aw.settings.v1'); // cheia cache-ului din SettingsRepository
        Cache::forget('aw.sitemap.v1');
    }

    public function down(): void
    {
        // datele firmei nu se șterg la revenire
    }
};
