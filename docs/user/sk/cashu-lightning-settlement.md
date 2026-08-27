---
title: Cashu Lightning vyrovnanie (CashuMelt)
category: payments
order: 3
meta_description: Cashu mint použitý len ako Lightning settlement bridge - faktúry ostávajú Lightning; plugin CashuMelt vyplatí sats na vašu Lightning adresu.
---

# Cashu Lightning vyrovnanie (CashuMelt)

Satflux **nevystavuje** Cashu faktúry a zákazníci **nikdy neplatia ecash tokenmi**. Faktúry vášho obchodu sú **Lightning**, ako každé iné. **Cashu (cez BTCPay plugin CashuMelt) je len prevodník na vyrovnanie**: na pozadí použije Cashu mint, ktorý prijaté sats skonvertuje a vyplatí na **Lightning adresu, ktorú si zvolíte**.

Skrátka: **Lightning dnu → mint konvertuje → sats von na vašu Lightning adresu.** Mint je len technická vrstva, nie zákaznícka platobná metóda.

> **Beta.** Táto cesta je experimentálna. Faktúra sa môže zobraziť ako zaplatená skôr, než vyrovnané prostriedky dorazia do vašej Lightning peňaženky; výpadok mintu alebo problém so smerovaním môže spôsobiť oneskorenie alebo stratu. Pred zapnutím musíte zaškrtnúť potvrdzovacie pole.

## Nastavenie

Otvorte **Pripojenie peňaženky** obchodu a sekciu **Cashu**. Potvrďte beta upozornenie a vyplňte:

- **Mint URL** - Cashu mint použitý na konverziu (musí byť `https://`). Predvolený mint je navrhnutý.
- **Lightning adresa** - kam sa vyrovnané sats vyplácajú. **Povinné.**
- **Jednotka faktúry** - `sat` alebo `usd` (ako sa interpretujú sumy).
- **Ponúkať Cashu pri platbe** - nechajte zapnuté, aby obchod používal túto vyrovnávaciu cestu.
- Voliteľne: **dôveryhodné minty** a **stropy rezervy poplatku** (max v sats a/alebo ako % zo sumy), ktoré ohraničujú, koľko poplatku za smerovanie (routing) Lightning platby môže výplata minúť.

Úprava existujúceho Cashu obchodu si znova vyžiada heslo (pokiaľ sa neprihlasujete frázou alebo passkeyom).

## Dva spôsoby použitia

- **Primárny** - obchod vyrovnáva cez mint na vašu Lightning adresu, bez samostatného pripojenia Lightning peňaženky.
- **Automatický fallback** - keď pripojíte Lightning peňaženku, CashuMelt môže ostať zapnutý paralelne s vašou Lightning adresou, takže vyrovnanie funguje, aj keď je hlavná Lightning cesta dočasne nedostupná. Prepnutie na Blink/Aqua fallback vypne.

## Sledovanie vyrovnaní

Stránka **Cashu settlements** vypisuje každé vyrovnanie a jeho stav - `SETTLED`, `PENDING`, `FAILED` alebo `MELT_COMPLETE` (skonvertované, záznam BTCPay dobieha). Zaseknuté riadky viete **skúsiť znova**. Bežné dôvody zlyhania sú zobrazené zrozumiteľne (napr. routing poplatok prekročil váš strop, alebo mint nepotvrdil Lightning výplatu).

## Dobré vedieť

- BTCPay obchodu potrebuje plugin **CashuMelt** (aktuálnu verziu). Ak vidíte chybu pluginu, aktualizujte ho.
- Prostriedky sa vyrovnajú na vašu Lightning adresu, ale počas konverzie prechádzajú **cez mint** - krátky bod dôvery - preto používajte len mint, ktorému dôverujete.
