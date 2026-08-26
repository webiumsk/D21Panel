# Self-billing (buyer-created invoices) - Track D

Self-billing = the **customer** issues the invoice on behalf of the **supplier**
(EN 16931 / Peppol BIS Self-Billing 3.0). In satflux the issuing `Company` is the
customer and the counterparty `CompanyContact` is the supplier, so on a
self-billed document the UBL parties are the reverse of an ordinary invoice.

## D1 - UBL self-billing profile (done)

`business_documents.self_billed` (boolean, default false) drives the UBL:

- `BusinessDocumentUblService::xml()` - when `self_billed` (and not the German
  XRechnung path): `CustomizationID` / `ProfileID` use the Peppol BIS
  **Self-Billing** URNs (`App\Support\Invoicing\SelfBillingUblProfile`),
  `InvoiceTypeCode` is **389** (self-billed credit notes use **261**), and
  `AccountingSupplierParty` / `AccountingCustomerParty` are
  swapped (contact = supplier, company = customer).
- `CanonicalInvoice.selfBilled` carries the flag from
  `CanonicalInvoiceBuilder` (`fromDocument` / `fromPayload`) into the UBL.
- The eFaktúra send path (`SapiSkComplianceGateway`) is type-code-agnostic - it
  forwards whatever UBL the builder produced, and sender = company (the customer
  transmits) / receiver = counterparty already matches self-billing, so no send
  change was needed. `supports()` still gates on Invoice / CreditNote, which a
  self-billed invoice is.

Tests: `tests/Feature/BusinessDocumentUblTest.php` (389 + self-billing profile +
party swap; regression that an ordinary invoice keeps 380 / billing / company as
supplier).

## D2 - document flag end-to-end (done)

`self_billed` now flows end-to-end. The counterparty contact already serves as
the supplier under self-billing (D1 swaps the roles), so no separate supplier
field is needed - only the boolean.

- Client: `schema.ts` document table (`selfBilled`), `documentCrud.ts`
  (`DocumentSavePayload.self_billed` + `buildDocumentFields`), `documentMap.ts`
  (`EvoluDocumentRow.selfBilled` -> API `self_billed`), `ephemeralBridge.ts`
  (both full-document payload builders), `useInvoiceDocument.ts` (form init /
  save / load), and a self-billing checkbox in `InvoiceForm.vue` shown for
  invoices and credit notes.
- Server: the ephemeral path validates `document.self_billed`
  (`EphemeralBusinessDocument{Bulk,Pdf}Request`, Efaktura extends Pdf) and
  `EphemeralDocumentFactory` sets it on the in-memory `BusinessDocument`;
  server-mode `StoreBusinessDocumentRequest` + `BusinessDocumentController`
  (create + update) persist it.

Tests: `documentSelfBilled.test.ts` (selfBilled -> self_billed mapping + default
false). The PHP plumbing mirrors the tested `pdf_show_signature` flow; the UBL
output itself is covered by D1's `BusinessDocumentUblTest`.

## D3 - supplier-side inbox routing (planned)

A received self-billed invoice (UBL `InvoiceTypeCode` 389) must land on the
supplier side as an **issued invoice**, not an expense. Extend
`UblExpenseDraftParser` to read `//cbc:InvoiceTypeCode`, then branch in
`EfakturaInboundService::importInboundDocument()` and client
`efakturaInboxImport.ts` so 389 becomes a document; match by number / variable
symbol.
