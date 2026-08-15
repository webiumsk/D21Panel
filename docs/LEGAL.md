# RegWatch - legislatívny monitoring modul (Satflux)

## Účel
Modul v Satfluxe (Laravel) na sledovanie a evidenciu daňovo-právnych
pravidiel pre jurisdikcie, v ktorých pôsobí Webium LLC a jeho klienti:
EU (SK, CZ, DE, AT, CH, HU, PL) a US LLC (Wyoming).
Skladá sa z: (1) znalostnej bázy pravidiel, (2) cron monitoringu
zmien v oficiálnych zdrojoch, (3) admin podstránky pre review.

## ⚠️ Kritické pravidlo - NIKDY neporušuj
Toto je daňovo-právny systém. NEGENERUJ konkrétne daňové sadzby,
registračné prahy, deadliny ani znenie pravidiel z vlastných znalostí.
Modelové znalosti legislatívy sú neaktuálne a jurisdikčne nepresné.
- Do `rules` seedni len PLACEHOLDER záznamy s `verified_on = NULL`
  a poznámkou "TODO: overiť z oficiálneho zdroja".
- Každé pravidlo MUSÍ mať `source_url` a dátum overenia človekom.
- Monitoring smie zapisovať IBA do `changes` (status='new'),
  NIKDY nesmie upravovať `rules`.
- Každý daňový/právny záver v UI nesie disclaimer: "Orientačné -
  overte s lokálnym odborníkom."

## Architektúra
- **rules** - zdroj pravdy, edituje LEN človek po review.
- **sources** - monitorované zdroje (Slov-Lex, e-Sbírka, BMF, ESTV...).
- **changes** - changelog, sem píše cron; human-in-the-loop pred `rules`.
- Cron (Laravel Scheduler) → fetch zdroj → diff oproti last_snapshot
  → Claude API klasifikuje relevantnosť → zápis do `changes` + notify.
- Admin podstránka `/admin/regwatch` - zoznam changes na review,
  workflow new → reviewed → applied/dismissed.

## Tech stack
- Laravel (existujúci Satflux backend)
- DB: Postgres (existujúca infra)
- Migrácie cez artisan, nie raw SQL
- Monitoring job: App\Jobs\RegWatch\*, scheduled v Kernel/routes
- Claude API len na klasifikáciu/sumarizáciu diffu (nie fakty)
- Notifikácie: cez existujúci notification kanál, prepínateľné

## Rozsah fázy 1
SK + CZ, témy: DPH/DPH prahy, reverse charge (cezhraničné B2B služby),
OSS režim, US LLC príjem v SK/CZ priznaní, CIT/daň z príjmu, archivácia.
Placeholder štruktúra + funkčný monitoring pipeline. Reálne hodnoty
dopĺňaš ty po overení.

