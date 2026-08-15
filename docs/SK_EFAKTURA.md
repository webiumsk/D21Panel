# Slovak e-faktura (digitálny poštár) - príprava do 1.1.2027

Od **1. januára 2027** platí pre tuzemské B2B transakcie na Slovensku povinná štruktúrovaná elektronická fakturácia (zákon č. 385/2025 Z. z.). Dokumenty idú cez sieť **Peppol** a certifikovaného **digitálneho poštára** (CPDS). Finančná správa SR spravuje výber poskytovateľa a národné pravidlá (SK CIUS).

Satflux **nie je** digitálny poštár. Modul Business Invoicing generuje Peppol BIS Billing 3.0 UBL a voliteľne odosiela dokumenty cez **SAPI-SK** API, ak si merchant nastaví vlastné credentials u svojho CPDS.

Modul e-faktúry sa zobrazuje a používa len pre slovenské firmy so stavom DPH **Platiteľ DPH** (`vat_status = payer`). Neplatitelia a čiastoční platitelia (§7 / §7a) ho v UI nevidia a API ho odmietne.

Rozsah povinností zákona sa pritom líši: **vystavovať** e-faktúry musia od 1.1.2027 len platitelia DPH, ale **prijímať** ich musia všetky zdaniteľné osoby, ktorým platiteľ fakturuje. Inbound (prijímanie) v Satflux je zatiaľ tiež obmedzený na platiteľov (rovnaká eligibilita v `pollAll`/`pollCompany`) - rozšírenie prijímania na neplatiteľov je vedomý otvorený bod pred fázou 2 zákona (1.7.2030).

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

Záložka **E-faktúra** v nastaveniach firmy (`eu_sk`, platiteľ DPH) je 3-krokový sprievodca:

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
3. `php artisan schedule:list` - overte registráciu `efaktura:poll-inbound` (15 min) a `efaktura:sync-compliance-status` (30 min).
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
  -> BusinessExpense + UBL príloha
  -> efaktura_inbound_receipts (dedup podľa providerDocumentId)
```

Peppol SMP lookup pred odoslaním rieši CPDS pri `POST /document/send` (422 ak príjemca nie je v sieti). Lokálna preflight kontrola overí, že kontakt má Peppol ID (IČO/DIČ/`peppol_participant_id`).

## UI (Fáza C)

- Záložka **E-faktúra** v nastaveniach firmy (`CompanySettingsForm`)
- Panel stavu, manuálne odoslanie a obnovenie stavu na `InvoiceShow` (vystavené faktúry a dobropisy, `eu_sk`)
- Peppol ID na kontakte odberateľa; manuálny inbound import v nastaveniach E-faktúra

## Referencie

- [BUSINESS_INVOICING.md](BUSINESS_INVOICING.md)
- [OpenPeppol Slovakia](https://peppol.org/learn-more/country-profiles/slovakia/)
- SAPI-SK špecifikácia: sapi-sk.sk (dobrovoľný štandard pre CPDS)
