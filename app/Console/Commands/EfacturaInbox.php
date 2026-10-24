<?php

namespace App\Console\Commands;

use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Finance\Efactura\AnafClient;
use App\Modules\Finance\Efactura\UblInvoiceBuilder;
use App\Modules\Finance\Models\EfacturaInboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('aw:efactura-inbox {--days=7}')]
#[Description('Descarcă facturile primite de la furnizori prin SPV (e-Factura), pentru înregistrare în Stocuri')]
class EfacturaInbox extends Command
{
    public function handle(AnafClient $anaf, SettingsRepository $settings): int
    {
        if (! $anaf->isAuthorized()) {
            $this->line('e-Factura neconfigurată — nimic de făcut.');

            return self::SUCCESS;
        }
        $cif = UblInvoiceBuilder::digits((string) $settings->filled('company.tax_id'));
        $new = 0;
        foreach ($anaf->messages($cif, (int) $this->option('days')) as $message) {
            if (($message['tip'] ?? '') !== 'FACTURA PRIMITA' || EfacturaInboxMessage::query()->where('anaf_message_id', (string) $message['id'])->exists()) {
                continue;
            }
            $record = EfacturaInboxMessage::query()->create([
                'anaf_message_id' => (string) $message['id'],
                'message_type' => (string) $message['tip'],
                'issuer_tax_id' => (string) ($message['cif_emitent'] ?? ''),
                'upload_index' => (string) ($message['id_solicitare'] ?? ''),
                'anaf_created_at' => isset($message['data_creare']) ? CarbonImmutable::createFromFormat('YmdHi', (string) $message['data_creare']) : null,
                'status' => 'new',
            ]);
            try {
                $zip = $anaf->download((string) $message['id']);
                $path = 'efactura/inbox/'.$record->anaf_message_id.'.zip';
                Storage::disk('local')->put($path, $zip);
                $record->forceFill(['zip_path' => $path, 'status' => 'downloaded', 'parsed' => $this->parse($zip)])->save();
            } catch (\Throwable $e) {
                $record->forceFill(['status' => 'error', 'parsed' => ['error' => $e->getMessage()]])->save();
            }
            $new++;
        }
        $this->info("{$new} facturi primite noi.");

        return self::SUCCESS;
    }

    /** Extrage datele principale din XML-ul facturii primite (pentru propunerea de înregistrare). */
    private function parse(string $zip): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'inbox');
        file_put_contents($tmp, $zip);
        $archive = new \ZipArchive;
        $data = null;
        if ($archive->open($tmp) === true) {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $name = $archive->getNameIndex($i);
                if (str_ends_with($name, '.xml') && ! str_starts_with($name, 'semnatura')) {
                    $xml = @simplexml_load_string((string) $archive->getFromIndex($i));
                    if ($xml) {
                        $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
                        $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
                        $first = fn (string $path) => (string) ($xml->xpath($path)[0] ?? '');
                        $data = [
                            'number' => $first('/*/cbc:ID'),
                            'issue_date' => $first('/*/cbc:IssueDate'),
                            'due_date' => $first('/*/cbc:DueDate'),
                            'supplier_name' => $first('//cac:AccountingSupplierParty//cac:PartyLegalEntity/cbc:RegistrationName'),
                            'supplier_tax_id' => $first('//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID') ?: $first('//cac:AccountingSupplierParty//cac:PartyLegalEntity/cbc:CompanyID'),
                            'net' => $first('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
                            'total' => $first('//cac:LegalMonetaryTotal/cbc:PayableAmount'),
                            'currency' => $first('/*/cbc:DocumentCurrencyCode'),
                            'lines' => array_map(fn ($line) => [
                                'name' => (string) ($line->xpath('cac:Item/cbc:Name')[0] ?? ''),
                                'quantity' => (string) ($line->xpath('cbc:InvoicedQuantity')[0] ?? ''),
                                'unit' => (string) (($line->xpath('cbc:InvoicedQuantity')[0] ?? null)?->attributes()['unitCode'] ?? ''),
                                'net' => (string) ($line->xpath('cbc:LineExtensionAmount')[0] ?? ''),
                                'vat_rate' => (string) ($line->xpath('cac:Item/cac:ClassifiedTaxCategory/cbc:Percent')[0] ?? ''),
                            ], $xml->xpath('//cac:InvoiceLine') ?: []),
                        ];
                    }
                }
            }
            $archive->close();
        }
        @unlink($tmp);

        return $data;
    }
}
