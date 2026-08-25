# Zdieľané firmy - obchodník + účtovník v jednej firme (Track C)

Cieľ: firmu vedie viac ľudí (majiteľ vystavuje faktúry, účtovník ich kontroluje, dopĺňa náklady, exportuje balík), pričom fakturačné dáta ostávajú E2EE - server obsah dokladov nevidí. Dnes je firma viazaná na jedného usera (`companies.user_id`, `EnsureCompanyOwnership`) a Evolu dáta sú šifrované pod jeho recovery frázou (AppOwner), takže "zdieľať firmu" by znamenalo zdieľať osobnú frázu.

## Model (rozhodnutie)

**Per-firma Evolu `SharedOwner`.** Evolu 7.4.1 poskytuje `createSharedOwner(secret)` - owner odvodený z náhodného 32-bajtového secretu (id + šifrovací kľúč + write key). Riadky zapísané s `{ ownerId: shared.id }` sú šifrované zdieľaným kľúčom a cez relay sa synchronizujú každému, kto owner zaregistroval (`evolu.useOwner`). Členovia dostanú secret (nie osobnú frázu), server dostane iba členstvo (`company_members`) kvôli autorizácii bridge endpointov a číslovaniu.

Alternatíva "zdieľané firmy v server mode" bola zamietnutá: SPA už nemá per-firma serverovú cestu (`VITE_INVOICING_LOCAL_FIRST` je build-global) a stratili by sme local-first prísľub práve pre firmy, kde na ňom záleží.

### Kľúčové technické fakty (overené v `@evolu/common` 7.4.1)

- Tabuľky sú kľúčované `(ownerId, id)` (`Sync.js` `on conflict ("ownerId","id")`): mutácia bez `{ ownerId }` na riadku pod SharedOwnerom ho **potichu forkne** do AppOwner partície. Preto všetky CRUD moduly musia ísť cez `scopedEvolu(evolu, ownerId)` (`resources/js/evolu/ownerScope.ts`), nie pamätať si option.
- `MutationOptions.ownerId` je podporované v insert/update/upsert; `evolu.useOwner(owner)` vracia unuse funkciu; každý owner má vlastný WebSocket transport (`createOwnerWebSocketTransport({url, ownerId})`), relay autorizuje len podľa ownerId.
- **Rotácia write key neexistuje na klientovi** - relay si write key zaregistruje pri prvom použití a `setWriteKey` je len na strane relay. Revokácia člena = odobratie členstva na serveri (bridge endpointy 403) + voliteľný **re-key** (nový SharedOwner + re-migrácia); už zosynchronizovanú históriu bývalý člen má a technicky môže ďalej písať do STARÉHO ownera, ktorý po re-key nikto nepoužíva. Toto je poctivé obmedzenie, ktoré UI musí povedať.
- Používateľ má asymetrický kľúč: `users.guest_recovery_public_key` (Ed25519, `services/accountSeed.ts`); `@noble/curves` vie ed25519 → x25519, takže invite môže byť sealed box na kľúč pozvaného (server nevidí secret v plaintexte). Fingerprint kľúča ukázať obom stranám (server kľúč servíruje, mohol by ho podvrhnúť).

## Fázy (PR-sized)

| PR | Obsah | Stav |
|---|---|---|
| **C0** | spike: `sharedOwner.ts` (secret, base64url, owner z secretu, relay transport, registrácia), `ownerScope.ts` (`scopedEvolu`), dev-only `window.__satfluxSharedOwnerSpike`, Vitest | **hotové** (táto vetva) |
| C1 | server: `company_members` (owner/accountant/member), `Company::isAccessibleBy`, `EnsureCompanyOwnership` membership-aware, `EnsureCompanyRole:owner` pre deštruktívne routy, `CompanyController::index` union s rolou, entitlement podľa vlastníka firmy, allocator test s 2 usermi | plán |
| C2 | klient: Evolu tabuľka `companyShare` (secret E2EE v AppOwner partícii), `sharedOwnerRegistry` v bootstrape, `scopedEvolu` pretiahnutý cez composables/CRUD (~150 volaní), dev assertion na owner mismatch | plán |
| C3 | konverzia firmy na zdieľanú + migrácia riadkov AppOwner → SharedOwner (`companyShareMigration.ts`: status `migrating` pred kopírovaním, upsert so zachovaným `createdAt`, verifikácia počtov/hashov, soft-delete originálov, resumable), `numberAllocatorBridge` s explicitným `bridgeCompanyId` (obaja píšu do jedného countera) | plán |
| C4 | invites: `company_invites`, sealed box na x25519 kľúč pozvaného, fingerprint, fallback share-link s payloadom v URL fragmente | plán |
| C5 | revokácia + re-key + audit (`documentEvent` s ownerId/rolou, `reserved_by_user_id`), UI správa členov, tento doc dopísať ako threat model | plán |

## C0 spike - runbook (dev build, dve prehliadače, jeden relay)

Predpoklad: `npm run dev` s `VITE_INVOICING_LOCAL_FIRST=true`, nakonfigurovaný relay (`VITE_EVOLU_RELAY_URL` alebo nastavenie v profile), dva prehliadače (A, B) s rôznymi účtami a odomknutým fakturačným modulom.

1. **A:** `const s = await __satfluxSharedOwnerSpike.createShare()` → vypíše `secret` a `ownerId`.
2. **A:** `await __satfluxSharedOwnerSpike.writeProbe(s.ownerId, "Zdieľaná s.r.o.")` → nový riadok pod shared ownerom. `await __satfluxSharedOwnerSpike.rows()` ukáže `partition: shared`.
3. **A:** tvrdenie 2 - vezmi id existujúcej firmy z `rows()` (partition `app`) a `writeProbe(s.ownerId, "Kópia", thatId)` → v `rows()` sú DVA riadky s rovnakým `id`, každý v inej partícii.
4. **A:** tvrdenie 3 - `softDeleteAppCopy(thatId)` → `rows()` ukáže app kópiu s `isDeleted: 1`, bežné dotazy (`allCompaniesQuery` filtrujú `isDeleted`) vidia už len shared riadok.
5. **B:** `await __satfluxSharedOwnerSpike.joinShare(s.secret)` → po chvíli `rows()` ukáže riadky zo zdieľanej partície (tvrdenia 1 a 4). B nikdy nedostal frázu A.
6. Upratanie: `leave(ownerId)` na oboch; riadky ostanú lokálne (spike nemaže).

### Výsledok (2026-08-25, relay `wss://evolu.satflux.io`, dev build proti localhost:8080, dva testovacie účty v dvoch izolovaných Chromium kontextoch, automatizované cez Playwright)

| Tvrdenie | Výsledok | Dôkaz |
|---|---|---|
| 1. SharedOwner registrovaný cez `useOwner` sa synchronizuje cez relay | **PASS** | riadky zapísané na A dorazili na B po `joinShare(secret)` do ~10 s |
| 2. `upsert` s `{ ownerId }` a ROVNAKÝM `id` zapíše samostatný riadok | **PASS** | `rows()` na A ukázalo 2 riadky s jedným `id`: `app/deleted=null` + `shared/deleted=null` |
| 3. soft-delete AppOwner kópie nechá viditeľný jediný (zdieľaný) riadok | **PASS** | po `softDeleteAppCopy`: `app/deleted=1`, `shared/deleted=null`; `allCompaniesQuery` (filtruje `isDeleted`) vidí 1 riadok |
| 4. druhý prehliadač len so secretom vidí dáta | **PASS** | B (iný účet, iná fráza) dostal oba zdieľané riadky; **súkromná AppOwner partícia A na B = 0 riadkov** (žiadny únik) |

Dôsledky pre ďalšie fázy: C3 migrácia „upsert pod SharedOwnerom s rovnakým id + soft-delete originálu" je potvrdená ako korektný postup. Pozorovanie do C2: `allCompaniesQuery` a ostatné dotazy vracajú **úniu všetkých partícií** bez rozlíšenia ownera - zoznam firiem teda zdieľanú firmu zobrazí automaticky, ale UI musí vedieť, ktorý riadok je zdieľaný (stĺpec `ownerId` do dotazov / `rowOwnerId`). Spike riadky boli po behu soft-deletnuté na oboch účtoch.

## Súbory

- `resources/js/evolu/sharedOwner.ts` - secret + owner + registrácia
- `resources/js/evolu/ownerScope.ts` - `scopedEvolu`, `rowOwnerId`
- `resources/js/evolu/sharedOwnerSpike.ts` - dev-only konzolové helpery (mimo produkčného bundle, `app.ts` guard `import.meta.env.DEV`)
- `resources/js/__tests__/sharedOwner.test.ts`
