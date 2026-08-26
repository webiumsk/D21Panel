<?php

namespace Tests\Unit;

use App\Services\Invoicing\Efaktura\UblExpenseDraftParser;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class UblExpenseDraftParserTest extends TestCase
{
    public function test_parses_supplier_invoice_fields_from_ubl(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-2026-001</cbc:ID>
  <cbc:IssueDate>2026-06-01</cbc:IssueDate>
  <cbc:DueDate>2026-06-15</cbc:DueDate>
  <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
  <cbc:BuyerReference>20260615001</cbc:BuyerReference>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyName><cbc:Name>Dodávateľ s.r.o.</cbc:Name></cac:PartyName>
    </cac:Party>
  </cac:AccountingSupplierParty>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">123.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertSame('INV-2026-001', $draft['external_number']);
        $this->assertSame('Dodávateľ s.r.o.', $draft['title']);
        $this->assertSame('2026-06-01', $draft['issue_date']);
        $this->assertSame('2026-06-15', $draft['due_date']);
        $this->assertSame('123.00', $draft['total']);
        $this->assertSame('EUR', $draft['currency']);
        $this->assertSame('20260615001', $draft['variable_symbol']);
        $this->assertFalse($draft['self_billed']);
    }

    public function test_flags_self_billed_invoice_type_code_389(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>SB-2026-001</cbc:ID>
  <cbc:IssueDate>2026-06-01</cbc:IssueDate>
  <cbc:InvoiceTypeCode>389</cbc:InvoiceTypeCode>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">100.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertSame('389', $draft['document_type_code']);
        $this->assertTrue($draft['self_billed']);
    }

    public function test_self_billed_credit_note_type_code_261_is_flagged(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<CreditNote xmlns="urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>SB-CN-1</cbc:ID>
  <cbc:IssueDate>2026-06-01</cbc:IssueDate>
  <cbc:CreditNoteTypeCode>261</cbc:CreditNoteTypeCode>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">50.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</CreditNote>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertSame('261', $draft['document_type_code']);
        $this->assertTrue($draft['self_billed']);
    }

    public function test_self_billed_document_captures_customer_and_lines(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>SB-DOC-1</cbc:ID>
  <cbc:IssueDate>2026-06-01</cbc:IssueDate>
  <cbc:InvoiceTypeCode>389</cbc:InvoiceTypeCode>
  <cac:AccountingSupplierParty>
    <cac:Party><cac:PartyName><cbc:Name>My Company</cbc:Name></cac:PartyName></cac:Party>
  </cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty>
    <cac:Party>
      <cac:PartyName><cbc:Name>Odberateľ a.s.</cbc:Name></cac:PartyName>
      <cac:PostalAddress>
        <cbc:StreetName>Hlavná 1</cbc:StreetName>
        <cbc:CityName>Bratislava</cbc:CityName>
        <cbc:PostalZone>81101</cbc:PostalZone>
        <cac:Country><cbc:IdentificationCode>SK</cbc:IdentificationCode></cac:Country>
      </cac:PostalAddress>
      <cac:PartyTaxScheme><cbc:CompanyID>SK2020304050</cbc:CompanyID></cac:PartyTaxScheme>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>Odberateľ a.s.</cbc:RegistrationName>
        <cbc:CompanyID>36012345</cbc:CompanyID>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingCustomerParty>
  <cac:InvoiceLine>
    <cbc:ID>1</cbc:ID>
    <cbc:InvoicedQuantity unitCode="C62">2</cbc:InvoicedQuantity>
    <cbc:LineExtensionAmount currencyID="EUR">200.00</cbc:LineExtensionAmount>
    <cac:Item>
      <cbc:Name>Widget</cbc:Name>
      <cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>23</cbc:Percent></cac:ClassifiedTaxCategory>
    </cac:Item>
    <cac:Price><cbc:PriceAmount currencyID="EUR">100.00</cbc:PriceAmount></cac:Price>
  </cac:InvoiceLine>
  <cac:InvoiceLine>
    <cbc:ID>2</cbc:ID>
    <cbc:InvoicedQuantity unitCode="HUR">1</cbc:InvoicedQuantity>
    <cbc:LineExtensionAmount currencyID="EUR">50.00</cbc:LineExtensionAmount>
    <cac:Item><cbc:Name>Práca</cbc:Name></cac:Item>
    <cac:Price><cbc:PriceAmount currencyID="EUR">50.00</cbc:PriceAmount></cac:Price>
  </cac:InvoiceLine>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertTrue($draft['self_billed']);
        $this->assertSame('Odberateľ a.s.', $draft['customer']['name']);
        $this->assertSame('36012345', $draft['customer']['registration_number']);
        $this->assertSame('SK2020304050', $draft['customer']['vat_number']);
        $this->assertSame('Bratislava', $draft['customer']['city']);
        $this->assertSame('SK', $draft['customer']['country']);

        $this->assertCount(2, $draft['lines']);
        $this->assertSame('Widget', $draft['lines'][0]['name']);
        $this->assertSame('2.00', $draft['lines'][0]['quantity']);
        $this->assertSame('C62', $draft['lines'][0]['unit']);
        $this->assertSame('100.00', $draft['lines'][0]['unit_price']);
        $this->assertSame('23.00', $draft['lines'][0]['tax_rate']);
        $this->assertSame('Práca', $draft['lines'][1]['name']);
    }

    public function test_non_self_billed_draft_has_no_customer_or_lines(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-1</cbc:ID>
  <cbc:IssueDate>2026-06-01</cbc:IssueDate>
  <cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="EUR">10.00</cbc:PayableAmount></cac:LegalMonetaryTotal>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertArrayNotHasKey('customer', $draft);
        $this->assertArrayNotHasKey('lines', $draft);
    }

    public function test_ordinary_invoice_380_is_not_self_billed(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-380</cbc:ID>
  <cbc:IssueDate>2026-06-01</cbc:IssueDate>
  <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">10.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertSame('380', $draft['document_type_code']);
        $this->assertFalse($draft['self_billed']);
    }

    public function test_parses_comma_decimal_amounts(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-COMMA</cbc:ID>
  <cbc:IssueDate>2026-06-01</cbc:IssueDate>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">123,45</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertSame('123.45', $draft['total']);
    }

    public function test_invalid_amount_falls_back_to_zero(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-BAD</cbc:ID>
  <cbc:IssueDate>2026-06-01</cbc:IssueDate>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">abc</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertSame('0.00', $draft['total']);
    }

    public function test_missing_issue_date_uses_today_fallback(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');

        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-NO-DATE</cbc:ID>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">10.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertSame('2026-06-10', $draft['issue_date']);
        $this->assertNull($draft['delivery_date']);

        Carbon::setTestNow();
    }

    public function test_malformed_issue_date_uses_today_fallback(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');

        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-BAD-DATE</cbc:ID>
  <cbc:IssueDate>not-a-date</cbc:IssueDate>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">10.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

        $draft = (new UblExpenseDraftParser)->parse($xml);

        $this->assertSame('2026-06-10', $draft['issue_date']);
        $this->assertNull($draft['delivery_date']);

        Carbon::setTestNow();
    }
}
