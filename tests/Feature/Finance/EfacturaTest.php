<?php

namespace Tests\Feature\Finance;

use App\Modules\Core\Models\Integration;
use App\Modules\Crm\Models\Customer;
use App\Modules\Finance\Efactura\EfacturaService;
use App\Modules\Finance\Models\DocumentSeries;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class EfacturaTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(BaseDataSeeder::class);
        foreach (['company.legal_name' => 'Firma Test SRL', 'company.tax_id' => 'RO12345674', 'company.reg_no' => 'J12/1/2026', 'company.registered_office' => 'Str. Memorandumului 1',
            'company.registered_city' => 'Cluj-Napoca', 'company.county_code' => 'RO-CJ', 'company.vat_payer' => 'yes', 'company.iban' => 'RO49AAAA1B31007593840000', 'finance.efactura_b2c' => true] as $k => $v) {
            $this->setting($k, $v);
        }
        DocumentSeries::query()->create(['document_type' => 'invoice', 'prefix' => 'AWT', 'next_number' => 1, 'padding' => 5, 'is_default' => true, 'is_active' => true]);
    }

    protected function issuedFor(array $customer): Invoice
    {
        $c = Customer::factory()->create($customer);
        $service = app(InvoiceService::class);

        return $service->issue($service->createDraft([['description' => 'Team building VR', 'quantity' => 12, 'unit_gross_minor' => 7000, 'vat_group' => 'standard']], $c));
    }

    private function company(): array
    {
        return ['type' => 'company', 'company_name' => 'Client SRL', 'company_tax_id' => 'RO76543210', 'company_reg_no' => 'J12/2/2020', 'billing_address' => 'Str. Avram Iancu 2', 'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj'];
    }

    private function authorize(): void
    {
        $integration = Integration::query()->where('key', 'efactura')->first();
        $integration->forceFill(['driver' => 'anaf', 'mode' => 'sandbox', 'status' => 'sandbox_tested']);
        $integration->storeCredentials(['client_id' => 'id', 'client_secret' => 'secret', 'access_token' => 'tok', 'refresh_token' => 'ref', 'access_expires_at' => (string) (time() + 3600)]);
        $integration->save();
    }

    public function test_b2b_invoice_generates_valid_cius_ro_xml(): void
    {
        $submission = app(EfacturaService::class)->generate($this->issuedFor($this->company()));

        $this->assertSame('validated', $submission->status, json_encode($submission->validation_errors));
        $xml = Storage::disk('local')->get($submission->xml_path);
        $this->assertStringContainsString('urn:efactura.mfinante.ro:CIUS-RO:1.0.1', $xml);
        $this->assertStringContainsString('<cbc:CountrySubentity>RO-CJ</cbc:CountrySubentity>', $xml);
        $this->assertStringContainsString('<cbc:CompanyID>RO76543210</cbc:CompanyID>', $xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="RON">840.00</cbc:PayableAmount>', $xml);
        $this->assertSame('validated', $submission->invoice->refresh()->efactura_status);
    }

    public function test_incomplete_buyer_address_fails_local_validation(): void
    {
        $submission = app(EfacturaService::class)->generate($this->issuedFor(['type' => 'company', 'company_name' => 'Fără Adresă SRL', 'company_tax_id' => '11111111']));

        $this->assertSame('validation_failed', $submission->status);
        $this->assertNotEmpty($submission->validation_errors);
    }

    public function test_upload_is_refused_without_anaf_authorization(): void
    {
        $submission = app(EfacturaService::class)->generate($this->issuedFor($this->company()));

        $this->expectException(RuntimeException::class);
        app(EfacturaService::class)->upload($submission);
    }

    public function test_upload_then_poll_marks_invoice_accepted_and_blocks_duplicate_submission(): void
    {
        $this->authorize();
        Http::fake([
            'api.anaf.ro/test/FCTEL/rest/upload*' => Http::response('<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001"/>'),
            'api.anaf.ro/test/FCTEL/rest/stareMesaj*' => Http::response('<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1" stare="ok" id_descarcare="9001"/>'),
            'api.anaf.ro/test/FCTEL/rest/descarcare*' => Http::response('PK-zip'),
        ]);
        $service = app(EfacturaService::class);
        $invoice = $this->issuedFor($this->company());
        $submission = $service->upload($service->generate($invoice));

        $this->assertSame('5001', $submission->upload_index);
        $this->assertSame('sent', $invoice->refresh()->efactura_status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/upload?') && str_contains($r->url(), 'cif=12345674') && $r->hasHeader('Authorization', 'Bearer tok'));

        try {
            $service->generate($invoice);
            $this->fail('O factură trimisă nu se retransmite.');
        } catch (RuntimeException) {
        }

        $this->travel(10)->minutes();
        $service->poll();
        $this->assertSame('accepted', $invoice->refresh()->efactura_status);
        $this->assertSame('accepted', $submission->refresh()->status);
    }

    public function test_b2c_invoice_uses_b2c_endpoint_and_13_zero_identifier(): void
    {
        $this->authorize();
        Http::fake(['api.anaf.ro/*' => Http::response('<header ExecutionStatus="0" index_incarcare="5002"/>')]);
        $service = app(EfacturaService::class);
        $submission = $service->generate($this->issuedFor(['type' => 'person', 'billing_address' => 'Str. Horea 3', 'billing_city' => 'Cluj-Napoca', 'billing_county' => 'CJ']));

        $this->assertStringContainsString('<cbc:CompanyID>0000000000000</cbc:CompanyID>', Storage::disk('local')->get($submission->xml_path));
        $service->upload($submission);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/uploadb2c?'));
    }

    public function test_rejection_allows_a_corrected_new_submission(): void
    {
        $this->authorize();
        Http::fake([
            'api.anaf.ro/test/FCTEL/rest/upload*' => Http::response('<header ExecutionStatus="0" index_incarcare="5003"/>'),
            'api.anaf.ro/test/FCTEL/rest/stareMesaj*' => Http::response('<header stare="nok" id_descarcare="9003"/>'),
            'api.anaf.ro/test/FCTEL/rest/descarcare*' => Http::response('nu-este-zip'),
        ]);
        $service = app(EfacturaService::class);
        $invoice = $this->issuedFor($this->company());
        $first = $service->upload($service->generate($invoice));
        $this->travel(10)->minutes();
        $service->poll();
        $this->assertSame('rejected', $invoice->refresh()->efactura_status);

        $second = $service->generate($invoice);
        $this->assertTrue($second->is_current);
        $this->assertFalse($first->refresh()->is_current);
    }

    public function test_uncertain_upload_is_not_retried_automatically(): void
    {
        $this->authorize();
        Http::fake(['api.anaf.ro/*' => fn () => throw new ConnectionException('timeout')]);
        $service = app(EfacturaService::class);
        $submission = $service->generate($this->issuedFor($this->company()));

        try {
            $service->upload($submission);
        } catch (RuntimeException) {
        }

        $this->assertSame('error', $submission->refresh()->status);
        $this->expectException(RuntimeException::class);
        $service->upload($submission);
    }
}
