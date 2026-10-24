<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Finance\Models\DocumentSeries;
use App\Modules\Finance\Services\AccountingExport;
use App\Modules\Finance\Services\InvoiceService;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class AccountingExportTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(BaseDataSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (['company.legal_name' => 'Firma Test SRL', 'company.tax_id' => 'RO12345674', 'company.reg_no' => 'J12/1/2026', 'company.registered_office' => 'Str. Test 1', 'company.vat_payer' => 'yes'] as $k => $v) {
            $this->setting($k, $v);
        }
        DocumentSeries::query()->create(['document_type' => 'invoice', 'prefix' => 'AWT', 'next_number' => 1, 'padding' => 5, 'is_default' => true, 'is_active' => true]);
        $this->travelTo('2026-11-10 12:00:00');
    }

    public function test_invoice_csv_lists_issued_documents_with_vat_breakdown_and_neutralises_formulas(): void
    {
        $service = app(InvoiceService::class);
        $customer = Customer::factory()->create(['type' => 'company', 'company_name' => '=HYPERLINK("x")', 'company_tax_id' => 'RO76543210']);
        $service->issue($service->createDraft([['description' => 'Sesiune VR', 'quantity' => 2, 'unit_gross_minor' => 6050, 'vat_group' => 'standard']], $customer));
        $service->createDraft([['description' => 'Ciornă', 'quantity' => 1, 'unit_gross_minor' => 100, 'vat_group' => 'standard']], null);

        $csv = app(AccountingExport::class)->csv('invoices', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertCount(2, $lines, 'Antet + o factură emisă; ciorna nu apare.');
        $this->assertStringContainsString('AWT00001;10.11.2026', $lines[1]);
        $this->assertStringContainsString("'=HYPERLINK", $lines[1], 'Valorile care încep cu = sunt neutralizate pentru Excel.');
        $this->assertStringContainsString('121,00', $lines[1]);
        $this->assertStringContainsString('21% S: 100,00 / 21,00', $lines[1]);
    }

    public function test_export_page_is_limited_to_finance_export_permission(): void
    {
        $accountant = tap(User::factory()->create(['is_active' => true]), fn ($user) => $user->assignRole('accountant'));
        $accountant->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());
        $reception = tap(User::factory()->create(['is_active' => true]), fn ($user) => $user->assignRole('reception'));

        $this->actingAs($accountant)->get('/admin/accounting-exports')->assertOk()->assertSee('Facturi emise (CSV)');
        $this->actingAs($reception)->get('/admin/accounting-exports')->assertForbidden();
    }
}
