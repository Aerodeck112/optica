<?php

namespace App\Modules\Finance\Efactura;

use App\Modules\Finance\Models\Invoice;
use DOMDocument;
use DOMElement;

/**
 * XML UBL 2.1 conform CIUS-RO 1.0.1 (RO e-Factura). Ordinea elementelor respectă schema UBL.
 * Facturile de stornare se transmit ca Invoice (380) cu valori negative și referință la factura inițială.
 * Persoanele fizice fără CNP se identifică prin codul 0000000000000 (13 zerouri), conform ANAF.
 */
class UblInvoiceBuilder
{
    public const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1';

    private const NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private DOMDocument $doc;

    public function build(Invoice $invoice): string
    {
        $invoice->loadMissing('lines', 'relatedInvoice');
        $seller = (array) $invoice->seller_snapshot;
        $buyer = (array) $invoice->buyer_snapshot;
        $currency = $invoice->currency;

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        $root = $this->doc->createElementNS(self::NS, 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $this->doc->appendChild($root);

        $this->cbc($root, 'CustomizationID', self::CUSTOMIZATION_ID);
        $this->cbc($root, 'ID', (string) $invoice->full_number);
        $this->cbc($root, 'IssueDate', $invoice->issue_date->toDateString());
        if ($invoice->due_date) {
            $this->cbc($root, 'DueDate', $invoice->due_date->toDateString());
        }
        $this->cbc($root, 'InvoiceTypeCode', '380'); // CIUS-RO acceptă 380, 384, 389, 751 (inclusiv pentru avans și stornare)
        foreach (array_filter([$invoice->notes, ! empty($seller['vat_on_collection']) ? 'TVA la incasare' : null]) as $note) {
            $this->cbc($root, 'Note', mb_substr((string) $note, 0, 300));
        }
        $this->cbc($root, 'DocumentCurrencyCode', $currency);
        if ($invoice->related_invoice_id && $invoice->relatedInvoice) {
            $ref = $this->cac($root, 'BillingReference');
            $doc = $this->cac($ref, 'InvoiceDocumentReference');
            $this->cbc($doc, 'ID', (string) $invoice->relatedInvoice->full_number);
            $this->cbc($doc, 'IssueDate', $invoice->relatedInvoice->issue_date->toDateString());
        }

        // furnizor
        $party = $this->cac($this->cac($root, 'AccountingSupplierParty'), 'Party');
        $this->address($party, (string) ($seller['address'] ?? ''), (string) ($seller['city'] ?? ''), (string) ($seller['county_code'] ?? ''), 'RO');
        if (! empty($seller['vat_payer'])) {
            $this->taxScheme($party, $this->vatId((string) $seller['tax_id']));
        }
        $legal = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', (string) $seller['name']);
        $this->cbc($legal, 'CompanyID', ! empty($seller['vat_payer']) ? (string) ($seller['reg_no'] ?? '') : self::digits((string) $seller['tax_id']));
        if (! empty($seller['email']) || ! empty($seller['phone'])) {
            $contact = $this->cac($party, 'Contact');
            if (! empty($seller['phone'])) {
                $this->cbc($contact, 'Telephone', (string) $seller['phone']);
            }
            if (! empty($seller['email'])) {
                $this->cbc($contact, 'ElectronicMail', (string) $seller['email']);
            }
        }

        // cumpărător
        $party = $this->cac($this->cac($root, 'AccountingCustomerParty'), 'Party');
        $this->address($party, (string) ($buyer['address'] ?? ''), (string) ($buyer['city'] ?? ''), CountyCodes::fromName((string) ($buyer['county'] ?? '')) ?? '', (string) ($buyer['country'] ?? 'RO'));
        $buyerTaxId = trim((string) ($buyer['tax_id'] ?? ''));
        $outsideVat = collect((array) $invoice->vat_breakdown)->contains(fn ($row) => $row['category'] === 'O');
        // BR-O-02: în afara sferei TVA nu se transmit coduri de TVA (nici al cumpărătorului)
        if ($buyerTaxId !== '' && str_starts_with(strtoupper($buyerTaxId), 'RO') && ! $outsideVat) {
            $this->taxScheme($party, strtoupper(str_replace(' ', '', $buyerTaxId)));
        }
        $legal = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', (string) $buyer['name']);
        $this->cbc($legal, 'CompanyID', $buyerTaxId !== '' ? self::digits($buyerTaxId) : '0000000000000');

        // plată
        if (! empty($seller['iban'])) {
            $means = $this->cac($root, 'PaymentMeans');
            $this->cbc($means, 'PaymentMeansCode', '42');
            $account = $this->cac($means, 'PayeeFinancialAccount');
            $this->cbc($account, 'ID', str_replace(' ', '', (string) $seller['iban']));
        }

        // TVA
        $taxTotal = $this->cac($root, 'TaxTotal');
        $this->amount($taxTotal, 'TaxAmount', $invoice->vat_total_minor, $currency);
        foreach ((array) $invoice->vat_breakdown as $row) {
            $sub = $this->cac($taxTotal, 'TaxSubtotal');
            $this->amount($sub, 'TaxableAmount', (int) $row['taxable_minor'], $currency);
            $this->amount($sub, 'TaxAmount', (int) $row['tax_minor'], $currency);
            $this->taxCategory($sub, 'TaxCategory', $row['category'], (string) $row['rate'], $row['exemption_reason'] ?? null);
        }

        // totaluri
        $totals = $this->cac($root, 'LegalMonetaryTotal');
        $this->amount($totals, 'LineExtensionAmount', $invoice->net_total_minor, $currency);
        $this->amount($totals, 'TaxExclusiveAmount', $invoice->net_total_minor, $currency);
        $this->amount($totals, 'TaxInclusiveAmount', $invoice->net_total_minor + $invoice->vat_total_minor, $currency);
        if ((int) $invoice->rounding_minor !== 0) {
            $this->amount($totals, 'PayableRoundingAmount', (int) $invoice->rounding_minor, $currency);
        }
        $this->amount($totals, 'PayableAmount', $invoice->gross_total_minor, $currency);

        foreach ($invoice->lines as $line) {
            $el = $this->cac($root, 'InvoiceLine');
            $this->cbc($el, 'ID', (string) $line->position);
            $qty = $this->cbc($el, 'InvoicedQuantity', self::decimal((string) $line->quantity));
            $qty->setAttribute('unitCode', $line->unit_code ?: 'H87');
            $this->amount($el, 'LineExtensionAmount', $line->line_net_minor, $currency);
            $item = $this->cac($el, 'Item');
            $this->cbc($item, 'Name', mb_substr($line->description, 0, 200));
            if ($line->item_code) {
                $this->cbc($this->cac($item, 'SellersItemIdentification'), 'ID', mb_substr($line->item_code, 0, 60));
            }
            $this->taxCategory($item, 'ClassifiedTaxCategory', $line->vat_category, (string) $line->vat_rate, null);
            $price = $this->cac($el, 'Price');
            $amount = $this->cbc($price, 'PriceAmount', self::decimal(ltrim((string) $line->unit_price, '-')));
            $amount->setAttribute('currencyID', $currency);
        }

        return $this->doc->saveXML();
    }

    private function address(DOMElement $party, string $street, string $city, string $county, string $country): void
    {
        $address = $this->cac($party, 'PostalAddress');
        $this->cbc($address, 'StreetName', $street !== '' ? mb_substr($street, 0, 150) : '-');
        $this->cbc($address, 'CityName', $city !== '' ? $city : '-');
        if ($county !== '') {
            $this->cbc($address, 'CountrySubentity', $county);
        }
        $this->cbc($this->cac($address, 'Country'), 'IdentificationCode', strtoupper($country ?: 'RO'));
    }

    private function taxScheme(DOMElement $party, string $vatId): void
    {
        $scheme = $this->cac($party, 'PartyTaxScheme');
        $this->cbc($scheme, 'CompanyID', $vatId);
        $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'VAT');
    }

    private function taxCategory(DOMElement $parent, string $name, string $category, string $rate, ?string $exemption): void
    {
        $cat = $this->cac($parent, $name);
        $this->cbc($cat, 'ID', $category);
        if ($category !== 'O') {
            $this->cbc($cat, 'Percent', self::decimal($rate));
        }
        if ($exemption && $name === 'TaxCategory' && in_array($category, ['E', 'O', 'AE', 'K', 'G'], true)) {
            $this->cbc($cat, 'TaxExemptionReason', $exemption);
        }
        $this->cbc($this->cac($cat, 'TaxScheme'), 'ID', 'VAT');
    }

    private function amount(DOMElement $parent, string $name, int $minor, string $currency): void
    {
        $el = $this->cbc($parent, $name, number_format($minor / 100, 2, '.', ''));
        $el->setAttribute('currencyID', $currency);
    }

    private function cbc(DOMElement $parent, string $name, string $value): DOMElement
    {
        $el = $this->doc->createElementNS(self::CBC, 'cbc:'.$name);
        $el->appendChild($this->doc->createTextNode($value));
        $parent->appendChild($el);

        return $el;
    }

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        $el = $this->doc->createElementNS(self::CAC, 'cac:'.$name);
        $parent->appendChild($el);

        return $el;
    }

    private function vatId(string $taxId): string
    {
        $digits = self::digits($taxId);

        return 'RO'.$digits;
    }

    public static function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    public static function decimal(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' || $value === '-' ? '0' : $value;
    }
}
