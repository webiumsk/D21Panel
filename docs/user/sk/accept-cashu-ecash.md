---
title: Prijímanie Cashu ecash (CashuMelt)
category: payments
order: 3
meta_description: Nechajte zákazníkov platiť Cashu ecash - plugin CashuMelt ho premelí na Lightning a vyplatí na vašu Lightning adresu.
---

# Prijímanie Cashu ecash (CashuMelt)

Satflux vie prijímať **Cashu ecash** pri platbe cez BTCPay plugin **CashuMelt**. Zákazník zaplatí ecash mincami z mintu, ktorému dôverujete; plugin potom ecash **premelí** na Lightning a vyplatí na **vašu Lightning adresu**.

> **Beta.** Podpora Cashu je experimentálna. Faktúra sa môže zobraziť ako zaplatená skôr, než premelené prostriedky dorazia do vašej Lightning peňaženky. Pred zapnutím musíte zaškrtnúť potvrdzovacie pole.

## Nastavenie

Otvorte **Pripojenie peňaženky** obchodu a sekciu **Cashu**. Potvrďte beta upozornenie a vyplňte:

- **Mint URL** - Cashu mint, z ktorého prijímate ecash (musí byť `https://`). Predvolený mint je navrhnutý.
- **Lightning adresa** - kam sa premelené prostriedky vyplácajú. **Povinné.**
- **Ponúkať Cashu pri platbe** - zapnite.
- **Jednotka faktúry** - `sat` alebo `usd`.
- Voliteľne: **dôveryhodné minty** a **stropy rezervy poplatku** (max v sats a/alebo ako % zo sumy), ktoré ohraničujú, koľko poplatku za smerovanie (routing) Lightning platby môže melt minúť.

Uloženie Cashu ako metódy obchodu odstráni Lightning možnosť pri platbe, takže BTCPay ponúkne len Cashu. Úprava existujúceho Cashu obchodu si znova vyžiada heslo (pokiaľ sa neprihlasujete frázou alebo passkeyom).

## Dva spôsoby použitia Cashu

- **Primárna metóda** - váš obchod je Cashu obchod (mint + Lightning adresa, bez Lightning pri platbe).
- **Automatický fallback** - keď pripojíte Lightning peňaženku, CashuMelt môže ostať zapnutý paralelne s vašou Lightning adresou, takže Cashu ostane dostupné, ak je Lightning swap cesta dočasne nedostupná. Prepnutie na Blink/Aqua fallback vypne.

## Sledovanie vysporiadaní

Stránka **Cashu settlements** vypisuje každú platbu a jej stav - `SETTLED`, `PENDING`, `FAILED` alebo `MELT_COMPLETE` (melt hotový, záznam BTCPay dobieha). Zaseknuté riadky viete **skúsiť znova**. Bežné dôvody zlyhania sú zobrazené zrozumiteľne (napr. routing poplatok prekročil váš strop, alebo mint nepotvrdil Lightning platbu).

## Predpoklady

- BTCPay obchodu potrebuje plugin **CashuMelt** (aktuálnu verziu). Ak vidíte chybu pluginu, aktualizujte ho.
- Keďže Cashu je systém krytý mintom, prijímajte len minty, ktorým dôverujete.
