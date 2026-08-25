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
| C1 | server: `company_members` (owner/accountant/member), `Company::isAccessibleBy`, `EnsureCompanyOwnership` membership-aware, `EnsureCompanyRole:owner` pre deštruktívne routy, `CompanyController::index` union s rolou, entitlement podľa vlastníka firmy, allocator test s 2 usermi | **hotové** (viď nižšie) |
| C2 | klient: Evolu tabuľka `companyShare` (secret E2EE v AppOwner partícii), registry v bootstrape, **automatické scopovanie mutácií na singletone** (namiesto prepisu ~150 volaní), `ownerId` vo výsledkoch všetkých dotazov | **hotové** (viď nižšie) |
| C3 | konverzia firmy na zdieľanú + migrácia riadkov AppOwner → SharedOwner (`companyShareMigration.ts`), `numberAllocatorBridge` s explicitným `bridgeCompanyId`, UI karta „Zdieľanie firmy“ | **hotové** (viď nižšie) |
| C4 | invites: `company_invites`, sealed box na x25519 kľúč pozvaného, fingerprint, fallback share-link s payloadom v URL fragmente | plán |
| C5 | revokácia + re-key + audit (`documentEvent` s ownerId/rolou, `reserved_by_user_id`), UI správa členov, tento doc dopísať ako threat model | plán |

## C1 - serverové členstvo (hotové)

- `company_members` (`role` owner/accountant/member, `invited_by`, `accepted_at`, `revoked_at`, unique company+user); model `CompanyMember` (`active()` = prijaté a neodvolané), enum `CompanyMemberRole`. Vlastník ostáva `companies.user_id` a nikdy nemá vlastný riadok.
- `Company::roleFor(User)` (owner implicitne, inak aktívny člen), `isAccessibleBy(User)` (owner / aktívny člen / support / admin), `Company::accessibleBy(User)` builder pre index.
- `EnsureCompanyOwnership` púšťa členov; nový `EnsureCompanyRole:owner` drží pri vlastníkovi: `DELETE /companies/{id}`, `reset-data`, `PATCH stores`, `PATCH email-settings`, `email-settings/test-smtp` (SMTP credentials) a `PATCH app-settings` (Stripe Tax / SAPI-SK secret). Všetko ostatné (doklady, kontakty, náklady, allocator, ephemeral bridges, profil firmy, export) je otvorené členom.
- `EnsurePlanAllowsBusinessInvoicing`: člen pracuje **pod plánom vlastníka** - pri route s `{company}` stačí, že vlastník má invoicing; pri company-less routách (index, ephemeral) stačí aspoň jedno aktívne členstvo pod oprávneným vlastníkom. Účtovník teda nepotrebuje vlastný Pro plán.
- `GET /companies` vracia vlastné + zdieľané firmy s `role`; `GET /companies/{id}` má `role` v payloade.
- Číslovanie: člen aj vlastník rezervujú z jedného countera (test s dvoma usermi - po sebe idúce čísla, idempotentný retry).
- Zatiaľ bez endpointov na pozvanie/odobranie (C4/C5) - riadky členstva vznikajú len z invitov.

## C2 - klientsky owner scoping (hotové)

Rozhodnutie oproti pôvodnému plánu: namiesto pretiahnutia `scopedEvolu` cez ~150 volaní v CRUD moduloch je scoping **centrálny** - `client.ts` exportuje singleton obalený `withCompanyOwnerScoping()` (`ownerScope.ts`) a všetci konzumenti (`useInvoicingEvolu`, `EvoluProvider`, CRUD moduly cez parameter) ho dostanú automaticky:

1. explicitný `options.ownerId` vždy vyhrá (migrácia, spike);
2. tabuľka `companyShare` ostáva vždy v AppOwner partícii (nesie secret zdieľania);
3. riadok s `companyId` ide do partície podľa registry (`companyShareRegistry.ts`: `companyId → SharedOwner`, plnená z tabuľky `companyShare` pri bootstrape a živo cez `subscribeQuery`);
4. inak sa owner odvodí z **indexu riadok → owner**: `createQuery` v proxy každému dotazu pripojí `.select("ownerId")` (systémový stĺpec; typy v aplikácii sa nemenia) a výsledky `loadQuery` / `loadQueries` / `getQueryRows` (tým aj `useQuery` z `@evolu/vue`) index plnia; update/upsert sa hľadá podľa `id`, child inserty (`documentLine`, `documentEvent`, `documentSnapshot`, `expenseAttachment`, `recurringProfileLine`, `bankTransactionMatch`) podľa rodičovského kľúča; novo zapísané riadky sa indexujú hneď, takže riadky faktúry po vložení dokladu skončia v tej istej partícii. Pri duplicitnom `id` v oboch partíciách (migračné okno) vyhráva zdieľaná kópia. Miss = zápis do AppOwner + dev warning.

Súkromné firmy → `undefined` = dnešné správanie, nič sa pre ne nemení. `invoicingSnapshot` (záloha/obnova/relay force push) tabuľku `companyShare` zámerne vynecháva. Schéma: `companyShare { companyId, sharedOwnerId, secretB64, role, status(migrating|active|revoked), bridgeCompanyId }` (aditívne). Testy: `__tests__/ownerScope.test.ts`.

Overené proti dev appke (účet s 2 firmami a 68 dokladmi): všetky výsledky dotazov nesú `ownerId`, stránky Faktúry/Kontakty/Náklady/Export/prehľad sa načítajú bez chýb aplikácie.

## C3 - konverzia firmy na zdieľanú (hotové)

`convertCompanyToShared(evolu, companyId)` v `resources/js/evolu/companyShareMigration.ts`:

1. **prepare** - firma existuje; bridge firma cez `ensureBridgeCompanyIdForLocalCompany` (bez nej `bridge_unavailable` - členovia musia číslovať v jednom counteri); ak už existuje `companyShare` riadok so stavom `active` → `already_shared`, so stavom `migrating` → **resume** s tým istým secretom.
2. riadok `companyShare { status: "migrating", secretB64, sharedOwnerId, bridgeCompanyId, role: "owner" }` sa zapíše (a počká sa na commit) **skôr než sa čokoľvek kopíruje**; owner sa zaregistruje a registry dostane stav `migrating`, takže scoping proxy už smeruje nové zápisy do zdieľanej partície.
3. **copy** - `collectCompanyRows` vyberie všetky živé riadky firmy naprieč 19 tabuľkami (deti podľa rodičovských kľúčov, `bankTransactionMatch` aj podľa dokladu), `pendingCopies` vynechá tie, ktoré už majú kópiu v zdieľanej partícii (idempotencia), a v poradí rodič → dieťa (`MIGRATION_ORDER`) sa každý upsertne s **rovnakým `id`** pod `scopedEvolu(evolu, sharedOwnerId)` (explicitný `ownerId` vyhrá nad registry) s čakaním na `onComplete`.
4. **verify** - znovu načíta všetko a `verifyMigrated` vyžaduje kópiu pre každý zdrojový riadok; pri medzere ostáva stav `migrating` a originály nedotknuté.
5. **cleanup** - originály sa soft-deletnú s explicitným AppOwner `ownerId` (nikdy hard delete), potom `companyShare.status = "active"`.

Resume: `companyShareBootstrap` po načítaní registry zavolá `resumePendingCompanyShareMigrations` pre riadky `migrating` vo vlastnej partícii. Poznámka: Evolu upsert neprijíma systémové stĺpce, takže kópie dostanú nový `createdAt`; poradie zápisu (rodič → dieťa, v poradí dotazov) zachováva relatívne poradie udalostí v `documentEvent`.

Allocator: `AllocatorIdentity.bridge_company_id` (z `companyShareInfo(companyId).bridgeCompanyId`) obíde identity match, takže vlastník aj člen rezervujú z jednej sekvencie. UI: `CompanyShareCard.vue` v profile firmy (local-first) - stav súkromná / konvertuje sa / zdieľaná + rola, potvrdzovací blok s dôsledkami a tlačidlom na zálohu, progres kopírovania. Testy: `__tests__/companyShareMigration.test.ts` (pure helpery + orchestrátor nad in-memory Evolu dvojníkom kľúčovaným `(ownerId, id)`: plná konverzia, resume bez druhého ownera, already_shared / not found / bridge_unavailable). **Runbook proti reálnej appke ešte nebežal** - pred C4: konverzia na účte A, `joinShare(secret)` na B, obaja vidia doklady a číslujú v jednom rade.

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

Dôsledky pre ďalšie fázy: C3 migrácia „upsert pod SharedOwnerom s rovnakým id + soft-delete originálu“ je potvrdená ako korektný postup. Pozorovanie do C2: `allCompaniesQuery` a ostatné dotazy vracajú **úniu všetkých partícií** bez rozlíšenia ownera - zoznam firiem teda zdieľanú firmu zobrazí automaticky, ale UI musí vedieť, ktorý riadok je zdieľaný (stĺpec `ownerId` do dotazov / `rowOwnerId`). Spike riadky boli po behu soft-deletnuté: stačilo ich zmazať **na A pod SharedOwnerom** - B (stále registrovaný na ten istý owner) dostal `isDeleted` cez relay bez vlastného zásahu, čo potvrdzuje, že aj mazanie/úpravy v zdieľanej partícii sa šíria všetkým členom (dôležité pre C3 re-freeze a C5 re-key).

## Súbory

- `resources/js/evolu/sharedOwner.ts` - secret + owner + registrácia
- `resources/js/evolu/ownerScope.ts` - `scopedEvolu`, `rowOwnerId`
- `resources/js/evolu/sharedOwnerSpike.ts` - dev-only konzolové helpery (mimo produkčného bundle, `app.ts` guard `import.meta.env.DEV`)
- `resources/js/__tests__/sharedOwner.test.ts`
