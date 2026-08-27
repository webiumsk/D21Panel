---
title: Prehľad pripojenia peňaženky
category: wallet-connection
order: 1
meta_description: Tri spôsoby pripojenia peňaženky - Lightning adresa, SamRock QR a Advanced - a čo Satflux ukladá.
---

# Prehľad pripojenia peňaženky

Skôr než obchod začne prijímať platby, potrebuje peňaženku. V obchode otvorte **Pripojenie peňaženky**. Satflux nikdy nedrží vaše prostriedky - pripájate **vlastnú** peňaženku a všetky platby idú priamo do nej.

**Pripojenie peňaženky** nájdete v obchode a rovnaké nastavenie beží aj pri vytváraní obchodu.

## Tri spôsoby pripojenia

Satflux ponúka tri taby. Väčšina ľudí použije prvý.

1. **Lightning adresa** (predvolené, najjednoduchšie) - vložte jednu Lightning adresu ako `vy@penazenka.com` a Satflux zvyšok vyrieši sám. Viď [Pripojenie akoukoľvek Lightning adresou](/documentation/connect-with-any-lightning-address).
2. **SamRock (QR)** - najjednoduchší spôsob pripojenia **Aqua**: vygenerujte jednorazový QR kód a naskenujte ho v aplikácii Aqua, ktorá nastaví BTCPay za vás. Viď [Pripojenie Aqua cez SamRock](/documentation/connect-aqua-with-samrock).
3. **Advanced (connection strings)** - jedno smart-paste pole, ktoré automaticky rozpozná, čo vložíte: Blink connection string, watch-only descriptor Aqua alebo **Bull Bitcoin**, NWC pairing string alebo akúkoľvek Lightning adresu.

Vstavaný **sprievodca peňaženkami** v aplikácii vypisuje každú podporovanú peňaženku s krokovými pokynmi.

## Čo Satflux ukladá

Pri pripojení Satflux uchováva len to, čo je potrebné na nastavenie BTCPay, a každé tajomstvo je **šifrované**. Pri neskoršom zobrazení vidíte **maskovanú** hodnotu a jej odhalenie vyžaduje opätovné overenie. Pri SamRock/Aqua sa v Satfluxe neukladá žiadny privátny descriptor - kľúče ostávajú v Aqua; BTCPay používa len watch-only descriptor. Viď [Čo ukladáme a ako je to chránené](/documentation/what-we-store-and-how-its-protected).

## Po pripojení

Satflux sa pokúsi nastaviť BTCPay automaticky a označí pripojenie ako **connected**. Ak niečo vyžaduje pozornosť, je to označené, aby mohla pomôcť podpora. Keď je peňaženka pripravená, obchod sa odomkne a môžete pridávať pokladne, Pay Buttony a Lightning adresy.

Na neskoršiu zmenu alebo odpojenie viď [Zrušenie alebo zmena údajov peňaženky](/documentation/revoking-or-changing-wallet-credentials).
