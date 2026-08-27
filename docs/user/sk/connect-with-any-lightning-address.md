---
title: Pripojenie akoukoľvek Lightning adresou
category: wallet-connection
order: 2
meta_description: Nasmerujte Lightning backend obchodu na akúkoľvek adresu vy@penazenka - Blink, Blitz, Flash, Coinos alebo akúkoľvek LUD-21 peňaženku.
---

# Pripojenie akoukoľvek Lightning adresou

Najjednoduchší spôsob pripojenia peňaženky je vložiť jednu **Lightning adresu** (`vy@penazenka.com`). Otvorte **Pripojenie peňaženky**, ostaňte na tabe **Lightning adresa**, zadajte adresu a pripojte. Satflux vyberie správny backend automaticky podľa domény adresy.

## Čo sa pripojí natívne

- **Blink** (`vy@blink.sv`) - nekustodiálne, len na príjem. Odporúčané na rýchly štart.
- **Blitz** (`…@blitzwalletapp.com`), **Flash** (`…@flashapp.me`), **Coinos** (`…@coinos.io`) - pripoja sa natívne.
- **Akákoľvek peňaženka s podporou LUD-21** overenia platieb - Satflux adresu pri pripojení skontroluje (rýchly „probe“) a ak peňaženka LUD-21 podporuje, pripojí ju ako štandardnú Lightning adresu.

## Ostatné adresy - Cashu (beta)

Ak adresa nepatrí medzi vyššie uvedené a nepodporuje LUD-21, Satflux ju vie použiť tak, že na ňu **vyrovnáva cez Cashu mint (beta)**. Uvidíte krátke beta upozornenie a potvrdzovacie zaškrtávacie pole. Ako to funguje a aké sú limity, viď [Cashu Lightning vyrovnanie](/documentation/cashu-lightning-settlement).

## Dobré vedieť

- Potrebujete len samotnú adresu - žiaden API kľúč. (Blink podporuje aj kustodiálne pripojenie cez API kľúč, ale nekustodiálna `@blink.sv` adresa je odporúčaný spôsob.)
- Peňaženka ostáva **vaša**; Satflux len smeruje BTCPay Lightning do nej.
- Chcete plne nekustodiálne nastavenie cez QR? Použite **Aqua** cez [SamRock](/documentation/connect-aqua-with-samrock).
- Chcete opakovateľnú adresu na príjem hostovanú na vlastnom obchode (`vy@váš-obchod`)? To je iná funkcia - viď [Lightning adresy obchodu](/documentation/store-lightning-addresses).
