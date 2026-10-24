<?php

namespace Tests\Feature\System;

use App\Modules\Core\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyDetailsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_only_empty_company_and_venue_fields(): void
    {
        DB::table('settings')->whereIn('key', ['company.tax_id', 'venue.address_street'])->delete();
        DB::table('settings')->updateOrInsert(['key' => 'contact.phone_main'], ['value' => '+40 700 000 000', 'is_encrypted' => false]);
        DB::table('settings')->updateOrInsert(['key' => 'company.legal_name'], ['value' => '', 'is_encrypted' => false]);

        (require database_path('migrations/2026_10_24_160601_fill_company_and_venue_details.php'))->up();

        $settings = app(SettingsRepository::class);
        $settings->flush();
        $this->assertSame('54556790', $settings->get('company.tax_id'));
        $this->assertSame('VIRTUAL REALITY ARENA S.R.L.', $settings->get('company.legal_name'));
        $this->assertSame('Str. Teodor Mihali nr. 31-35', $settings->get('venue.address_street'));
        $this->assertSame('+40 700 000 000', $settings->get('contact.phone_main'));
    }
}
