# Balík pre účtovníka (accountant export)

Jeden ZIP za firmu a obdobie, ktorý účtovná kancelária nahrá do svojho programu bez ručného prepisovania - odpoveď na scenár "prijaté a vystavené faktúry pre viacerých klientov naraz" z praxe účtovníkov (eFaktúra od 2027).

## Obsah ZIPu

| Cesta | Obsah | Pre koho |
|---|---|---|
| `manifest.txt` | firma, obdobie, počty, upozornenia (cudzie meny, neznáme sadzby DPH, preskočené ISDOC) | človek |
| `pohoda/invoices.xml` | Stormware **Pohoda XML** (`dataPack` 2.0, agenda `inv:invoice`): vystavené faktúry (`issuedInvoice` / `issuedCreditNotice` / `issuedAdvanceInvoice`) aj prijaté (`receivedInvoice`) | Pohoda SK/CZ - Soubor › Datová komunikace › XML import |
| `csv/issued.csv`, `csv/received.csv`, `csv/vat_summary.csv` | univerzálne tabuľky (UTF-8 BOM, CRLF, RFC 4180, guard proti formula injection) | Excel, Money S3, iDoklad, vlastné skripty |
| `issued/{číslo}.isdoc` | ISDOC per vystavený doklad | **KROS Omega / Alfa+** (importujú ISDOC, Pohoda XML nie), Pohoda tiež |
| `issued/{číslo}.pdf` | vizuálna faktúra | archív |
| `issued/{číslo}.ubl.xml` | Peppol BIS 3.0 UBL (voliteľné) | e-faktúra archív |
| `received/{interné číslo}/…` | prílohy prijatých dokladov (PDF, obrázky, UBL z Peppolu) | archív / OCR |

## Prečo tieto formáty

- **Pohoda XML** má verejnú, stabilnú schému (`http://www.stormware.cz/schema/version_2/`) a importuje vystavené aj prijaté faktúry vrátane rozpisu DPH.
- **KROS** (Omega, Alfa+) nemá verejnú import schému pre tretie strany - preto balík vždy nesie **ISDOC**, ktorý KROS importuje. Pohoda XML KROS neprijme.
- **CSV** pokrýva zvyšok (Money S3, iDoklad, tabuľkové kontroly).

## Známe obmedzenia (uvedené aj v manifeste)

- Prijaté doklady (náklady) nemajú rozpis položiek ani DPH - v Pohoda XML idú celé do sadzby `none`, účtovník ich rozdelí pri importe. ISDOC sa pre ne negeneruje; ak prišli cez Peppol, priloží sa pôvodné UBL.
- Doklady v inej mene ako predvolená mena firmy idú do `foreignCurrency` s kurzom **1** (Satflux kurz neukladá) - upraviť v Pohode.
- Sadzby DPH sa mapujú na Pohoda buckety (`high` / `low` / `third` / `none`) podľa **aktuálneho** zoznamu sadzieb jurisdikcie (`JurisdictionRules::vat_rates`, SK 23/19/5, CZ 21/12). Historická sadzba (napr. SK 20 % spred 2025) nemá bucket → `none` + upozornenie.
- Poradie elementov v `invoiceHeader` je podľa `invoice.xsd`; XSD sa nevaliduje automaticky - pred prvým produkčným použitím spraviť ručný import do Pohoda demo (checklist nižšie).

## Kód

- `app/Support/Invoicing/Accounting/` - `ReceivedExpenseItem` (+ `fromModel`), `ReceivedExpenseAttachment`, `PohodaVatRateMapper`
- `app/Services/Invoicing/Accounting/` - `PohodaXmlWriter`, `AccountingCsvWriter`, `AccountantPackageOptions` (+ `fromArray`), `AccountantPackageBuilder`
- Buildery sú čisté PHP nad `CanonicalInvoice` (`CanonicalInvoiceBuilder::fromDocument`) a `ReceivedExpenseItem`; PDF / ISDOC / UBL sa berú z existujúcich služieb (`BusinessDocumentPdfService`, `BusinessDocumentIsdocService`, `BusinessDocumentUblService`). Server nič neukladá - rovnaký builder poslúži serverovému režimu (z DB) aj local-first ephemeral bridge (z dočasného payloadu).
- Testy: `tests/Unit/Invoicing/{PohodaXmlWriter,AccountingCsvWriter,AccountantPackageBuilder}Test.php`

## Endpointy (B2)

Všetky pod `/api/invoicing`, auth:sanctum + `EnsurePlanAllowsBusinessInvoicing` (Pro+):

| Metóda | Cesta | Režim |
|---|---|---|
| `GET` | `/companies/{company}/accountant-export?from=YYYY-MM-DD&to=YYYY-MM-DD&formats[]=pohoda&formats[]=csv&include_pdf=1&include_isdoc=1&include_ubl=0&include_expense_attachments=1` | server mode - doklady (nie draft, s číslom) a náklady (nie cancelled) podľa `issue_date`, prílohy zo storage; `EnsureCompanyOwnership` |
| `POST` | `/ephemeral/accountant-export` | local-first bez serverovej firmy - `company` snapshot + `documents[]` (ako `bulk/pdf-zip`) + `expenses[]` (base64 prílohy) + `options{from,to,formats,include_*}` |
| `POST` | `/companies/{company}/documents/ephemeral/accountant-export` | local-first s bridge firmou (audit sa viaže na firmu) |

Limity: `invoicing.accountant_export_max_rows` (500 dokladov a 500 nákladov, inak 413 / 422) a `invoicing.accountant_export_max_attachment_bytes` (64 MB dekódovaných príloh per ephemeral request, inak 413); jedna príloha max ~512 KB base64, povolené MIME: PDF, PNG, JPEG, WebP, XML. UI (B3) pri prekročení chunkuje po mesiacoch. Server nič neukladá - iba audit `company.accountant_export` / `business_document.ephemeral_accountant_export` s počtami.

## Stav

- [x] B1 - buildery + unit testy
- [x] B2 - endpointy (nižšie)
- [ ] B3 - UI stránka "Export pre účtovníka" (obdobie, formáty, obsah, download)
- [ ] manuálny import `pohoda/invoices.xml` do Pohoda demo a ISDOC do KROS Omega demo
