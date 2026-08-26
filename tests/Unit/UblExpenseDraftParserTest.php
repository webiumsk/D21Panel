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
