<?php

namespace App\Modules\Finance\Efactura;

use App\Modules\Finance\Models\Invoice;
use DOMDocument;
use DOMXPath;

/**
 * Validare locală înainte de transmitere: structura XML, regulile de calcul EN 16931 și câteva reguli CIUS-RO
 * frecvent încălcate. Nu înlocuiește validarea ANAF; statusul rezultat este „Validat local”.
 */
class EfacturaValidator
{
    /** @return array<int, string> erorile găsite (gol = valid) */
    public function validate(Invoice $invoice, string $xml): array
    {
        $errors = [];
        $doc = new DOMDocument;
        if (! @$doc->loadXML($xml)) {
            return ['XML invalid (nu poate fi citit).'];
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $value = fn (string $path) => trim((string) $xp->evaluate("string({$path})"));
        $sum = fn (string $path) => array_sum(array_map(fn ($n) => (float) $n->nodeValue, iterator_to_array($xp->query($path))));
        $money = fn (float $v) => (int) round($v * 100);

        if ($value('/inv:Invoice/cbc:CustomizationID') !== UblInvoiceBuilder::CUSTOMIZATION_ID) {
            $errors[] = 'CustomizationID trebuie să fie specificația CIUS-RO 1.0.1.';
        }
        if (! preg_match('/\d/', $value('/inv:Invoice/cbc:ID'))) {
            $errors[] = 'BR-RO-010: numărul facturii trebuie să conțină cel puțin o cifră.';
        }
        foreach (['AccountingSupplierParty' => 'furnizorului', 'AccountingCustomerParty' => 'cumpărătorului'] as $party => $label) {
            $base = "/inv:Invoice/cac:{$party}/cac:Party";
            if ($value("{$base}/cac:PartyLegalEntity/cbc:RegistrationName") === '') {
                $errors[] = "Lipsește denumirea {$label}.";
            }
            $country = $value("{$base}/cac:PostalAddress/cac:Country/cbc:IdentificationCode");
            if ($value("{$base}/cac:PostalAddress/cbc:StreetName") === '-' || $value("{$base}/cac:PostalAddress/cbc:CityName") === '-') {
                $errors[] = "BR-RO: adresa {$label} este incompletă (stradă și localitate).";
            }
            if ($country === 'RO') {
                $county = $value("{$base}/cac:PostalAddress/cbc:CountrySubentity");
                if (! CountyCodes::isValid($county)) {
                    $errors[] = "BR-RO-110/111: județul {$label} trebuie să fie un cod ISO (ex. RO-CJ).";
                }
                if ($county === 'RO-B' && ! preg_match('/^SECTOR[1-6]$/', $value("{$base}/cac:PostalAddress/cbc:CityName"))) {
                    $errors[] = "BR-RO-100: pentru București, localitatea {$label} trebuie să fie SECTOR1–SECTOR6.";
                }
            }
        }
        if ($value('/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID') === '') {
            $errors[] = 'Lipsește identificatorul legal al furnizorului.';
        }
        $sellerCui = $value('/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID')
            ?: (string) (((array) $invoice->seller_snapshot)['tax_id'] ?? '');
        if (! CuiValidator::isValid($sellerCui)) {
            $errors[] = 'CUI-ul furnizorului nu are o cifră de control validă.';
        }
        $buyerVat = $value('/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');
        $sellerCui = $sellerCui !== '' ? $sellerCui : (string) (((array) $invoice->seller_snapshot)['tax_id'] ?? '');
        if ($buyerVat !== '' && str_starts_with($buyerVat, 'RO') && ! CuiValidator::isValid($buyerVat)) {
            $errors[] = 'CUI-ul cumpărătorului nu are o cifră de control validă.';
        }

        $lines = $money($sum('/inv:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount'));
        $net = $money((float) $value('/inv:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'));
        $taxExclusive = $money((float) $value('/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
        $taxInclusive = $money((float) $value('/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'));
        $taxTotal = $money((float) $value('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
        if ($lines !== $net) {
            $errors[] = 'BR-CO-10: suma valorilor nete ale liniilor diferă de totalul fără TVA.';
        }
        if ($taxExclusive + $taxTotal !== $taxInclusive) {
            $errors[] = 'BR-CO-15: total cu TVA ≠ total fără TVA + TVA.';
        }
        if ($money($sum('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount')) !== $taxTotal) {
            $errors[] = 'BR-CO-14: TVA total ≠ suma TVA pe cote.';
        }
        foreach ($xp->query('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal') as $sub) {
            $taxable = $money((float) $xp->evaluate('string(cbc:TaxableAmount)', $sub));
            $tax = $money((float) $xp->evaluate('string(cbc:TaxAmount)', $sub));
            $category = $xp->evaluate('string(cac:TaxCategory/cbc:ID)', $sub);
            $percent = (float) $xp->evaluate('string(cac:TaxCategory/cbc:Percent)', $sub);
            if ($category === 'S' && abs((int) round($taxable * $percent / 100) - $tax) > 1) {
                $errors[] = "BR-S-09: TVA pe cota {$percent}% nu corespunde bazei.";
            }
            if ($category === 'O' && $xp->query('//cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme | //cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme')->length > 0) {
                $errors[] = 'BR-O-02: o factură în afara sferei TVA nu poate conține coduri de TVA (furnizor sau cumpărător).';
            }
            if (in_array($category, ['E', 'O'], true) && trim($xp->evaluate('string(cac:TaxCategory/cbc:TaxExemptionReason)', $sub)) === '') {
                $errors[] = "BR-{$category}-10: lipsește motivul scutirii.";
            }
        }
        if ($xp->query('/inv:Invoice/cac:InvoiceLine')->length === 0) {
            $errors[] = 'Factura nu are linii.';
        }
        $rounding = $money((float) $value('/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableRoundingAmount'));
        $payable = $money((float) $value('/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
        if ($taxInclusive + $rounding !== $payable) {
            $errors[] = 'BR-CO-16: suma de plată ≠ total cu TVA + rotunjire.';
        }
        if (abs($rounding) > 5) {
            $errors[] = 'Ajustarea de rotunjire este neobișnuit de mare.';
        }
        if ($invoice->gross_total_minor !== $payable) {
            $errors[] = 'Totalul din XML diferă de factura emisă.';
        }

        return $errors;
    }
}
