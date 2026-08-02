# Satflux Tickets (BTCPay plugin)

Satflux neimplementuje tickety lokálne - volá Greenfield API pluginu
**Satflux Tickets** na BTCPay Serveri (samostatný fork Satoshi Tickets od
TChukwuletu, od 2.0.0 s vlastnou identitou
`BTCPayServer.Plugins.SatfluxTickets`).

## Ktorý plugin nasadiť

Na produkcii používaj **Satflux Tickets** z monorepa:

- Repozitár: `webiumsk/BTCPayServerPluginsWebium` (lokálne
  `~/apps/bitcoin/BTCPayServerPlugins`), vetva `main`
- Runbook (údržba, upstream = manuálny port):  
  `Plugins/BTCPayServer.Plugins.SatfluxTickets/FORK_MAINTENANCE.md`
- Zoznam fork-only zmien:  
  `Plugins/BTCPayServer.Plugins.SatfluxTickets/CHANGELOG-FORK.md`
- API cesty: kanonicky `satflux-tickets`, legacy `satoshi-tickets` aliasy
  zostávajú funkčné pre staré integrácie a rozposlané linky leteniek.

**Ďalšia plánovaná kontrola fork + upstream:** 20. jún 2026.

## Min. verzie pluginu (doplň po release)

| Satflux release / vetva | Satoshi Tickets (fork) | BTCPay Raffle (ak bundle) |
|-------------------------|------------------------|---------------------------|
| Event raffle bundle | ≥ **1.3.6.4** (fork; reflection ValueTuple) | ≥ **1.3.1.0** (`IRaffleEventBundleService`) |

Ak create event vráti `BTCPay Raffle plugin … required`, na serveri beží starý Raffle (napr. 1.3.0.2) bez bundle API - nahraj novší `.btcpay` a reštartuj BTCPay.

## Kód v Satflux

- API proxy: `app/Http/Controllers/TicketController.php`, `app/Services/BtcPay/TicketService.php`
- UI: `resources/js/pages/stores/TicketsShow.vue`
- Event raffle bundle v UI: polia `bundledRaffleId`, `bundledRaffleTicketsPerAdmission`

## Poznámka

Upstream autor vyvíja plugin nezávisle; Satflux závisí od fork endpointov (napr. `includeInactive`, offline tickets, bundle polia). Pred upgradeom BTCPay pluginu vždy skontroluj `FORK_MAINTENANCE.md`.
