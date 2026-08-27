---
title: Pripojenie Aqua cez SamRock
category: wallet-connection
order: 3
meta_description: Najjednoduchší, plne nekustodiálny spôsob pripojenia peňaženky Aqua - naskenujte jednorazový QR kód.
---

# Pripojenie Aqua cez SamRock

**SamRock** je najjednoduchší spôsob pripojenia peňaženky **Aqua** a je plne nekustodiálny - kľúče ostávajú v Aqua. Namiesto exportu a vkladania descriptora naskenujete jednorazový QR kód a aplikácia Aqua nastaví BTCPay za vás.

## Kroky

1. Otvorte **Pripojenie peňaženky** a zvoľte tab **SamRock (QR)**.
2. Zadajte **záložnú Lightning adresu**. Uchová sa ako Cashu záložná výplata, aby platby dorazili aj keď je Lightning swap cesta dočasne nedostupná.
3. Kliknite **Generovať QR**. Satflux zobrazí jednorazový kód (platný pár minút).
4. V aplikácii **Aqua** naskenujte QR. Aqua nastaví Bitcoin, Lightning (cez Boltz) a voliteľne Liquid na vašom BTCPay obchode.
5. Satflux zistí, keď párovanie prebehne, a pripojenie dokončí.

To je všetko - žiaden descriptor na kopírovanie. Satflux pri SamRock pripojení neukladá žiaden privátny descriptor; kľúče ostávajú v Aqua; BTCPay používa len watch-only descriptor.

## Poznámky

- **Bull Bitcoin** SamRock nepoužíva - pripojíte ho vložením watch-only descriptora. Viď [Descriptory Aqua a Bull Bitcoin](/documentation/aqua-and-bull-descriptor).
- SamRock nastaví Lightning cez **Boltz** swap (Lightning cez Liquid). Satflux zobrazuje indikátor pripravenosti Boltz, aby ste videli, že funguje.
- Ak je obchod momentálne len na Cashu, najprv ho prepnite na Lightning nastavenie, potom použite SamRock.

## Radšej vložiť descriptor?

Aqua viete pripojiť aj manuálne cez watch-only output descriptor - viď [Descriptory Aqua a Bull Bitcoin](/documentation/aqua-and-bull-descriptor).
