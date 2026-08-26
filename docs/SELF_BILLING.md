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

## D3.1 - supplier-side detection (done)

A received self-billed invoice is the SUPPLIER's own sale (revenue), not an
expense, so it must never be booked as a cost.

- `UblExpenseDraftParser` now reads `//cbc:InvoiceTypeCode` / `//cbc:CreditNoteTypeCode`
  and adds `document_type_code` + `self_billed` (389/261) to the draft.
- `EfakturaInboundService::importInboundDocument()` parks a self-billed document
  as a **pending inbox item** in BOTH modes (never a `BusinessExpense`).
- Client: `isSelfBilledInboxEntry()` reads `draft.self_billed`;
  `importEfakturaInboxEntry()` refuses a self-billed entry
  (`self_billed_not_expense`); `EfakturaInboxPanel` shows a "self-billed" badge
  and a note instead of the "import as expense" button.

Tests: parser (389/261 -> self_billed, 380 -> not), inbound routing (self-billed
UBL -> pending inbox, zero `business_expenses`), client (detect + refuse expense
import).

## D3.2 - parse the full self-billed document (done)

For a self-billed receipt, `UblExpenseDraftParser` now also extracts, into the
inbox `draft_json`:

- `customer` - the UBL `AccountingCustomerParty` (the party who created the
  document; on the supplier side this becomes the contact): name, legal name,
  registration number, VAT number, address.
- `lines` - each `cac:InvoiceLine` / `cac:CreditNoteLine`: name, description,
  quantity (+ unit code), unit price, tax rate, line total.

These are added only for self-billed documents (ordinary expenses keep the lean
header draft). Tests: `UblExpenseDraftParserTest` (customer + lines captured for
389; absent for a non-self-billed draft).

## D3.3 - book a self-billed receipt as an issued document (done)

`evolu/efakturaSelfBilledBooking.ts` turns a pending self-billed inbox item into
an issued local `document` (revenue):

- resolve the counterparty contact from `draft.customer` (match by registration
  number -> VAT -> name, else create via `insertLocalContactFromForm`);
- document id + line ids are stable ids derived from the inbox item, so a
  re-import upserts the same rows on every device (mirrors `insertLocalExpense`);
- the number comes from the received UBL (self-billed docs are numbered by the
  issuer - no allocator call); `document_type_code` 389 -> invoice, 261 -> credit
  note; `self_billed = true`; totals reconcile the tax to the UBL payable amount;
- dedupe: a DIFFERENT document with the same number (one the supplier created
  manually) blocks a second copy (`duplicate_number`).
- `EfakturaInboxPanel` shows a "Book as issued document" button for self-billed
  items; the UBL itself is not attached (there is no local documentAttachment
  table - it stays on the inbox item until marked imported).

Tests: `efakturaSelfBilledBooking.test.ts` covers the pure mapping (type code,
contact form, contact matching, totals, stable id). The Evolu writes cannot be
unit-tested under jsdom, so end-to-end still needs a manual runbook: share a
company A -> B, A issues a self-billed invoice to B, B books it from the inbox
and checks the resulting issued document (parties, number, lines, self_billed).
