---
title: Pripojenie Blink
category: wallet-connection
order: 5
meta_description: Pripojte peňaženku Blink k obchodu - rýchla nekustodiálna adresa len na príjem, alebo starší API kľúč.
---

# Pripojenie Blink

**Blink** je rýchly spôsob, ako začať prijímať Lightning. Pripojiť sa dá dvoma spôsobmi.

## Odporúčané: vaša Blink Lightning adresa (nekustodiálna, len na príjem)

Stačí vložiť vašu Blink adresu `vy@blink.sv` na tabe **Lightning adresa** v Pripojení peňaženky. Je to **len na príjem** - Satflux a BTCPay vedia sledovať a prijímať platby, ale nevedia posielať ani presúvať vaše prostriedky. Nič ďalšie nenastavujete.

## Staršie: Blink connection string s API kľúčom

Blink podporuje aj plný connection string s API kľúčom:

```text
type=blink;server=…;api-key=blink_…;wallet-id=…;
```

API kľúč získate v **Blink dashboarde → API Keys** (vytvorte kľúč s právami read + receive). Reťazec vložte na tabe **Advanced**.

> Toto pripojenie cez API kľúč je považované za **EU-legacy**. Satflux môže zobraziť upozornenie s odporúčaním prejsť na nekustodiálnu `@blink.sv` adresu. Blink sa neodstraňuje - forma s adresou je len odporúčaná, jednoduchšia možnosť.

## Ktorú použiť?

- Chcete najjednoduchší štart? Použite **`@blink.sv` adresu**.
- Máte už nastavený API kľúč? Stále funguje.
- Chcete držať vlastné kľúče s QR nastavením? Zvážte **Aqua** cez [SamRock](/documentation/connect-aqua-with-samrock).

Čo presne znamená „len na príjem“, viď [Nemôžeme pristupovať k vaším prostriedkom ani ich presúvať](/documentation/we-cannot-access-or-move-your-funds).
