<?php

namespace Tests\Feature\Finance;

use App\Modules\Crm\Models\Customer;
use App\Modules\Finance\Models\DocumentSeries;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\VatCalculator;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\Support\BookingFixtures;
use Tests\Support\RunsParallelProcesses;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use BookingFixtures, DatabaseMigrations, RunsParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(BaseDataSeeder::class);
        foreach (['company.legal_name' => 'Firma Test SRL', 'company.tax_id' => 'RO12345678', 'company.reg_no' => 'J12/1/2026', 'company.registered_office' => 'Str. Test 1, Cluj-Napoca', 'company.vat_payer' => 'yes', 'company.county_code' => 'RO-CJ'] as $k => $v) {
            $this->setting($k, $v);
        }
        DocumentSeries::query()->create(['document_type' => 'invoice', 'prefix' => 'AWT', 'next_number' => 1, 'padding' => 5, 'is_default' => true, 'is_active' => true]);
        $this->travelTo('2026-11-10 12:00:00');
    }

    private function service(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    public function test_vat_is_computed_per_rate_and_matches_gross_prices(): void
    {
        $calc = app(VatCalculator::class);
        // 3 × 33,33 lei cu TVA 21% + 1 × 10 lei cu TVA 11%
        $result = $calc->calculate([
            ['description' => 'A', 'quantity' => 3, 'unit_gross_minor' => 3333, 'vat_rate' => '21.00', 'vat_category' => 'S'],
            ['description' => 'B', 'quantity' => 1, 'unit_gross_minor' => 1000, 'vat_rate' => '11.00', 'vat_category' => 'S'],
        ], true);

        $this->assertSame(9999 + 1000, $result['gross_minor'], 'Totalul cu TVA este exact suma prețurilor afișate.');
        foreach ($result['breakdown'] as $row) {
            $this->assertSame((int) round($row['taxable_minor'] * (float) $row['rate'] / 100), $row['tax_minor'], 'TVA = bază × cotă, rotunjit.');
        }
        $this->assertSame(array_sum(array_column($result['lines'], 'line_net_minor')), $result['net_minor'], 'Suma liniilor = total fără TVA.');
    }

    public function test_issue_allocates_consecutive_numbers_and_freezes_the_document(): void
    {
        $customer = Customer::factory()->create();
        $first = $this->service()->issue($this->service()->createDraft([['description' => 'Sesiune VR', 'quantity' => 2, 'unit_gross_minor' => 6000, 'vat_group' => 'standard']], $customer));
        $second = $this->service()->issue($this->service()->createDraft([['description' => 'Sesiune VR', 'quantity' => 1, 'unit_gross_minor' => 6000, 'vat_group' => 'standard']], $customer));

        $this->assertSame('AWT00001', $first->full_number);
        $this->assertSame('AWT00002', $second->full_number);
        $this->assertSame(12000, $first->gross_total_minor);
        $this->assertSame('21.00', $first->lines->first()->vat_rate);
        $this->assertNotNull($first->content_hash);
        Storage::disk('local')->assertExists($first->pdf_path);

        $this->expectException(LogicException::class);
        $first->refresh()->forceFill(['gross_total_minor' => 1])->save();
    }

    public function test_vat_rate_follows_the_issue_date(): void
    {
        $this->travelTo('2025-07-15 12:00:00');
        $invoice = $this->service()->issue($this->service()->createDraft([['description' => 'X', 'quantity' => 1, 'unit_gross_minor' => 11900, 'vat_group' => 'standard']], null));

        $this->assertSame('19.00', $invoice->lines->first()->vat_rate);
        $this->assertSame(10000, $invoice->net_total_minor);
    }

    public function test_storno_negates_the_original_and_can_be_issued_only_once(): void
    {
        $original = $this->service()->issue($this->service()->createDraft([['description' => 'Aniversare', 'quantity' => 1, 'unit_gross_minor' => 50000, 'vat_group' => 'standard']], Customer::factory()->create()));

        $storno = $this->service()->storno($original, 'Eveniment anulat');

        $this->assertSame('credit_note', $storno->document_type);
        $this->assertSame(-50000, $storno->gross_total_minor);
        $this->assertSame($original->id, $storno->related_invoice_id);
        $this->expectException(RuntimeException::class);
        $this->service()->storno($original, 'din nou');
    }

    public function test_cannot_issue_without_company_data(): void
    {
        $this->setting('company.tax_id', '');
        $draft = $this->service()->createDraft([['description' => 'X', 'quantity' => 1, 'unit_gross_minor' => 1000, 'vat_group' => 'standard']], null);

        $this->expectException(RuntimeException::class);
        $this->service()->issue($draft);
    }

    public function test_non_vat_payer_issues_with_category_o(): void
    {
        $this->setting('company.vat_payer', 'no');
        $invoice = $this->service()->issue($this->service()->createDraft([['description' => 'X', 'quantity' => 1, 'unit_gross_minor' => 5000, 'vat_group' => 'standard']], null));

        $this->assertSame('O', $invoice->lines->first()->vat_category);
        $this->assertSame(0, $invoice->vat_total_minor);
        $this->assertSame(5000, $invoice->gross_total_minor);
    }

    public function test_concurrent_issuing_never_duplicates_or_skips_numbers(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Necesită MySQL/MariaDB.');
        }
        $ids = [];
        foreach (range(1, 6) as $i) {
            $ids[] = $this->service()->createDraft([['description' => "Linie {$i}", 'quantity' => 1, 'unit_gross_minor' => 1000, 'vat_group' => 'standard']], null)->id;
        }

        $results = $this->runInParallel(array_map(fn ($id) => ['invoice', $id, '', 'web', "i{$id}"], $ids));

        $this->assertCount(6, array_filter($results, fn ($r) => $r['ok'] ?? false), json_encode($results));
        $numbers = Invoice::query()->where('status', 'issued')->orderBy('number')->pluck('number')->all();
        $this->assertSame(range(1, 6), $numbers);
    }
}
