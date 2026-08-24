# Slovak e-faktura (digitálny poštár) - príprava do 1.1.2027

Od **1. januára 2027** platí pre tuzemské B2B transakcie na Slovensku povinná štruktúrovaná elektronická fakturácia (zákon č. 385/2025 Z. z.). Dokumenty idú cez sieť **Peppol** a certifikovaného **digitálneho poštára** (CPDS). Finančná správa SR spravuje výber poskytovateľa a národné pravidlá (SK CIUS).

Satflux **nie je** digitálny poštár. Modul Business Invoicing generuje Peppol BIS Billing 3.0 UBL a voliteľne odosiela dokumenty cez **SAPI-SK** API, ak si merchant nastaví vlastné credentials u svojho CPDS.

Modul e-faktúry je dostupný len slovenským firmám (`jurisdiction = eu_sk`). **Odosielanie** (send, auto-send, Peppol sender ID) je vyhradené firmám so stavom DPH **Platiteľ DPH** (`vat_status = payer`); neplatitelia a čiastoční platitelia (§7 / §7a) vidia záložku aj sprievodcu v režime **iba príjem** - bez auto-send a bez Peppol sender nastavení, outbound API volania pre nich vracajú 422.

Rozsah povinností zákona sa pritom líši: **vystavovať** e-faktúry musia od 1.1.2027 len platitelia DPH, ale **prijímať** ich musia všetky zdaniteľné osoby, ktorým platiteľ fakturuje. Satflux to zrkadlí rozdelením eligibility (`CompanyEfakturaEligibility::supportsOutbound` vs `supportsInbound`): odosielanie a auto-send sú len pre platiteľov, **prijímanie (inbound) je otvorené každej slovenskej firme** bez ohľadu na stav DPH. Neplatiteľ a čiastočný platiteľ (§7 / §7a) vidí záložku E-faktúra v režime „iba príjem" - bez auto-send a bez Peppol sender nastavení; server odmietne `efaktura_auto_send=true` pre takúto firmu (422).

## Kto čo rieši

| Úloha | Zodpovednosť |
|-------|----------------|
| Výber CPDS na portáli FS SR (eFaktúra) | Merchant |
| Generovanie UBL / ISDOC | Satflux |
| Peppol doručenie a reporting na FS SR | Certifikovaný digitálny poštár |
| Automatické odoslanie po vystavení faktúry | Satflux (voliteľné, per firma) |
| Pre predplatné Webium LLC (WY) | Mimo SK tuzemskej e-faktúry |

## Čo Satflux už podporuje

- **UBL** export (Peppol BIS 3.0) - `GET .../documents/{id}/ubl`
- **ISDOC** export a embed do EU PDF
- **SK CIUS** polia v UBL: Peppol scheme `0208` (IČO), `0245` (DIČ), `PartyLegalEntity`, `PaymentMeans`/IBAN, UN/ECE unit codes
- Per-company nastavenia v `app_settings` (credentials merchanta)
- Async odoslanie cez `SubmitBusinessDocumentCompliance` job (za `EFAKTURA_ENABLED=true`)
- SAPI-SK JSON `document/send` (metadata + UBL payload) podľa špecifikácie CPDS
- Inbound polling `efaktura:poll-inbound` - import UBL do **nákladov** + acknowledge
- API: `POST .../efaktura/send`, `POST .../efaktura/poll-inbound`, `GET .../efaktura/compliance`, `POST .../efaktura/compliance/refresh`, `POST .../efaktura/test-connection`, `POST .../efaktura/compliance-bulk` (+ ephemeral varianty pre local-first)
- Voliteľný scheduler `efaktura:sync-compliance-status` (globálny `EFAKTURA_SAPI_SEND_DETAIL_PATH` alebo per-preset detail path)
- **Derivované Peppol ID**: sender ID sa automaticky odvodí z DIČ (`0245`) alebo IČO (`0208`) firmy; explicitná hodnota v nastaveniach ho prebije
- **Presety poštárov (CPDS)**: admin ich spravuje na `/admin/efaktura-cpds`; aktívne presety predvypĺňajú base URL v sprievodcovi a ich hosty sú dôveryhodné pre SSRF kontrolu
- **Viditeľnosť**: indikátor "Odošle sa ako eFaktúra" na formulári faktúry, "e" badge so stavom v zozname, preflight kontrola odberateľa pred odoslaním, readiness checklist pre SK platiteľov (aj pred globálnym zapnutím)

## Nastavenie (merchant) - sprievodca

Záložka **E-faktúra** v nastaveniach firmy (`eu_sk`; platitelia DPH vidia plný sprievodcu, neplatitelia a §7 / §7a variant „iba príjem" bez kroku auto-send a bez Peppol sender ID) je 3-krokový sprievodca:

1. **Vyberte digitálneho poštára** - dropdown s presetmi (spravuje admin) alebo "Iný (zadať URL)". Výber na [portáli Finančnej správy](https://www.financnasprava.sk) ostáva na merchantovi.
2. **Prepojte svoj účet** - `client_id` + `client_secret` od poštára a tlačidlo **Otestovať pripojenie** (jednorazový OAuth pokus, úspech sa uloží ako `efaktura_connection_tested_at`).
3. **Možnosti** - auto-send (pri prvom zapnutí modulu predvolene zaškrtnutý), inbound. Peppol participant ID je v "Rozšírené" - bežný merchant ho nerieši, derivuje sa z DIČ/IČO.

U odberateľov SK stačí doplniť IČO/DIČ na kontakte (voliteľne explicitné **Peppol ID odberateľa**). Readiness checklist na zozname faktúr ukazuje počet kontaktov, ktorým údaje chýbajú.

Každý merchant si vyberá iného digitálneho poštára - **base URL musí byť per firma**, nie globálne.

## Globálna konfigurácia (ops)

```env
EFAKTURA_ENABLED=false
EFAKTURA_PROVIDER=sapi_sk
# EFAKTURA_SAPI_BASE_URL=  # voliteľný fallback pre lokálny dev; v produkcii nastavte per firma
# EFAKTURA_SAPI_SEND_DETAIL_PATH=/sapi/v1/document/send/{id}  # voliteľné; CPDS-špecifické sledovanie stavu
```

`EFAKTURA_ENABLED=false` je default - bez globálneho zapnutia sa gateway nebinduje na SAPI (ostáva noop) a v UI sa nezobrazí záložka E-faktúra ani panel na faktúre (`GET /api/config` → `efaktura_enabled`). Readiness checklist pre SK platiteľov sa zobrazuje aj pri vypnutom module (redukovaný "pripravte si kontakty" stav; termín povinnosti ide z `efaktura_mandatory_from` v `/api/config`).

Po zmene `.env`: `php artisan optimize:clear`

## Aktivácia (ops runbook)

1. Cez admin editor `/admin/efaktura-cpds` doplňte **overené** presety poštárov (RegWatch pravidlo: žiadne neoverené URL; per-preset `send_detail_path` s `{id}` placeholderom prebíja globálny).
2. Nastavte `EFAKTURA_ENABLED=true` (+ voliteľne `EFAKTURA_SAPI_ALLOWED_HOSTS`), potom `php artisan optimize:clear` a **reštart queue workerov** (config je v nich cachovaný).
3. `php artisan schedule:list` - overte registráciu `efaktura:poll-inbound` (15 min), `efaktura:sync-compliance-status` (30 min) a `efaktura:purge-inbound-inbox` (denne).
4. `php artisan efaktura:doctor` - globálny stav + per-company readiness (eligibilita, derivované Peppol ID, allowlist verdikt base URL, credentials, configured). `--company=<uuid>` obmedzí na jednu firmu, `--live` spraví reálnu SAPI-SK autentifikáciu.

### Sandbox E2E checklist (manuálne, pred produkčným zapnutím)

Všetky CPDS volania sú zatiaľ overené len cez `Http::fake` - proti reálnemu sandboxu poštára treba ručne overiť:

- [ ] token grant (`efaktura:doctor --live`)
- [ ] `document/send` happy path + akceptácia UBL validátorom poštára
- [ ] 422 recipient-not-found mapovanie ("Recipient is not registered in the Peppol network.")
- [ ] idempotency-key retry (opakovaný send toho istého dokumentu)
- [ ] status detail (`send_detail_path` daného CPDS) + `efaktura:sync-compliance-status`
- [ ] inbound list/detail/acknowledge + import do nákladov

## Architektúra

```text
BusinessDocumentIssueService::issue()
  -> ComplianceSubmissionService::queueIfEligible()  [ak auto_send]
       -> SubmitBusinessDocumentCompliance (queue)
            -> SapiSkComplianceGateway::submit()
                 -> BusinessDocumentUblService::xml()
                 -> SapiSkClient::sendDocument()
                 -> business_document_compliance row
```

Provider-agnostic vrstva: [`SapiSkClient`](../app/Services/Invoicing/Efaktura/SapiSkClient.php) implementuje štandard SAPI-SK; base URL smeruje na konkrétneho CPDS (ePošťák, Flowis, …).

## Fallback bez API

Merchant môže stiahnuť UBL/XML z detailu faktúry a nahrať do webového rozhrania poštára. PDF e-mailom **nie je** e-faktúra pre B2B od 2027.

## Inbound (Fáza B)

```text
efaktura:poll-inbound (scheduler každých 15 min, ak EFAKTURA_ENABLED)
  -> SapiSkClient list/detail/acknowledge
  -> UblExpenseDraftParser
  -> server mode:   BusinessExpense + UBL príloha na disku
  -> local-first:   efaktura_inbound_receipts ako INBOX položka
                    (inbox_status=pending, draft_json, ubl_encrypted, evolu_expense_id)
  -> efaktura_inbound_receipts (dedup podľa providerDocumentId)
```

### Inbox pre local-first firmy

Local-first firma nemá serverové náklady, takže poller (`EfakturaInboundService`, vetva podľa `Company::usesServerInvoicing()`) uloží prijatý doklad ako **inbox položku** na bridge firme: rozparsovaný draft nákladu, UBL šifrované `Crypt`-om, stabilné `evolu_expense_id` (klient z neho odvodí Evolu id, takže druhé zariadenie neduplikuje) a denormalizovaný súhrn (dodávateľ, číslo, suma, mena). Acknowledge voči CPDS prebehne hneď pri polli (položka je bezpečne uložená).

Endpointy (`EnsureCompanyOwnership`, `{company}` = bridge firma):

| Metóda | Cesta | Účel |
|---|---|---|
| `GET` | `/companies/{company}/efaktura/inbox` | pending položky (súhrn + draft, bez UBL) |
| `GET` | `/companies/{company}/efaktura/inbox/{receipt}` | detail vrátane dešifrovaného UBL (pre prílohu) |
| `POST` | `/companies/{company}/efaktura/inbox/{receipt}/imported` | klient importoval do Evolu - draft aj UBL sa zo servera zmažú |
| `POST` | `/companies/{company}/efaktura/inbox/{receipt}/dismiss` | zahodiť |

Credentials pre inbound si local-first klient uloží na bridge firmu cez existujúci `PATCH /companies/{company}/app-settings` (`efaktura_enabled`, `efaktura_inbound_enabled=true`, `efaktura_auto_send=false`, base URL, client id/secret) - žiadny samostatný subscription endpoint. Ručný poll: `POST /companies/{bridge}/efaktura/poll-inbound`.

**Klient (A3):** `CompanyEfakturaSettingsForm` pri local-first uložení zrkadlí inbound podmnožinu nastavení na bridge firmu (`syncInboundSettingsToBridge`, bridge cez `ensureBridgeCompanyIdForLocalCompany`; chyba mostu neruší lokálne uloženie, len sa zobrazí) a tlačidlo „Stiahnuť teraz" polluje bridge. `evolu/efakturaInboxLive.ts` (singleton, init v `InvoicingAppHeader` ako WooCommerce inbox) periodicky sťahuje pending položky a `reconcileEfakturaInboxWithLocalExpenses` skryje/vyčistí tie, ktoré už lokálne existujú (import na inom zariadení). `EfakturaInboxPanel.vue` na stránke Náklady: Import = `importEfakturaInboxEntry` → náklad pod stabilným id (`createIdFromString("satflux.efaktura-expense.v1." + evolu_expense_id)`, upsert = idempotentné) + UBL ako XML príloha (nad 384 KB sa preskočí s upozornením) → `imported` na serveri; Zahodiť = `dismiss`. Vitest: `__tests__/efakturaInboxImport.test.ts`.

**Poctivé konštatovanie:** pre local-first firmy s inboundom sú CPDS credentials a dočasné prijaté UBL na serveri (šifrované), kým ich klient neimportuje alebo kým ich nezahodí `efaktura:purge-inbound-inbox` (denne, `EFAKTURA_INBOUND_INBOX_RETENTION_DAYS`, default 60 - riadok ostáva kvôli dedup, obsah sa zmaže). `efaktura:doctor` vypisuje počet pending položiek per firma.

Peppol SMP lookup pred odoslaním rieši CPDS pri `POST /document/send` (422 ak príjemca nie je v sieti). Lokálna preflight kontrola overí, že kontakt má Peppol ID (IČO/DIČ/`peppol_participant_id`).

## UI (Fáza C)

- Záložka **E-faktúra** v nastaveniach firmy (`CompanySettingsForm`)
- Panel stavu, manuálne odoslanie a obnovenie stavu na `InvoiceShow` (vystavené faktúry a dobropisy, `eu_sk`)
- Peppol ID na kontakte odberateľa; manuálny inbound poll v nastaveniach E-faktúra (local-first cez bridge firmu); panel prijatých e-faktúr na stránke Náklady (local-first)

## Referencie

- [BUSINESS_INVOICING.md](BUSINESS_INVOICING.md)
- [OpenPeppol Slovakia](https://peppol.org/learn-more/country-profiles/slovakia/)
- SAPI-SK špecifikácia: sapi-sk.sk (dobrovoľný štandard pre CPDS)
