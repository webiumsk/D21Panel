---
title: "Aqua: získanie výstupného deskriptora"
category: wallet-connection
order: 4
meta_description: "Step-by-step: Aqua wallet + Boltz plugin → export output descriptor (watch-only). Clarify: no private keys, only public/descriptor. Where to paste it in D21 Panel. One descriptor per store (BTCPay limitation)."
---

Na pripojenie Aqua peňaženky k obchodu v Satfluxe potrebujete **watch-only výstupný deskriptor**. Popisuje adresy vašej peňaženky len pomocou verejných údajov — bez súkromných kľúčov. BTCPay ho používa s pluginom **Boltz**, aby obchod mohol prijímať Bitcoin a Lightning platby. Plnú kontrolu máte vy: my môžeme vytvárať adresy a prijímať prostriedky, ale **nemôžeme** míňať.

---

**Krok 1 — Aqua peňaženka a plugin Boltz**

1. Použite **Aqua** (Liquid + Lightning peňaženka) a overte, že je nastavená.
2. Z Aqua exportujte **výstupný deskriptor**. V Aqua to býva v Nastaveniach alebo Export. Vyberte možnosť **watch-only** / **verejný deskriptor**. **Neexportujte** nič, čo obsahuje súkromné kľúče.

---

**Krok 2 — Čo exportujete: iba verejné / deskriptor, žiadne súkromné kľúče**

Deskriptor je reťazec, ktorý popisuje, ako sa z rozšírených verejných kľúčov (xpub, ypub, zpub) vašej peňaženky odvodzujú adresy. Obsahuje:

- **Iba verejné údaje** — Rozšírené verejné kľúče, derivačné cesty, deskriptorové funkcie (napr. wpkh, ct, slip77, elsh).

- **Žiadne súkromné kľúče** — Žiadne xprv, yprv, zprv, prv ani podobné. Ak export obsahuje súkromné kľúče, **nepoužívajte ho**. Použite watch-only/verejný deskriptor.

Príklad (zjednodušený):

ct(slip77(...),elsh(wpkh(xpub6...)))

Váš skutočný deskriptor bude dlhší a obsahovať váš xpub. Vložte celý reťazec, ako ho exportujete z Aqua.

---

**Krok 3 — Kde vložiť deskriptor v paneli Satflux**

Deskriptor môžete zadať na jednom z dvoch miest:

- **Pri vytváraní obchodu** — V sprievodcovi vytvorením obchodu v **Kroku 2 (Typ peňaženky)**:

- Zvoľte **Aqua Wallet** (Aqua + Boltz).

- Do poľa **Descriptor**, ktoré sa zobrazí, vložte celý deskriptor (jeden riadok, bez extra medzier).

- Pokračujte do Kroku 3 a vytvorte obchod.

- **Keď už obchod existuje** — Otvorte obchod, v bočnom paneli prejdite na **Pripojenie peňaženky** (LN Wallet Connection). Do formulára vložte deskriptor a uložte.

Hodnota sa ukladá **šifrovaná**. Používa sa len na to, aby BTCPay mohol odvodzovať adresy a prijímať platby; súkromné kľúče nikdy nevidíme ani neukladáme.

---

**Obmedzenie BTCPay: jeden deskriptor na jedno použitie**

BTCPay umožňuje každý výstupný deskriptor použiť **len raz** na jednej inštancii BTCPay. Ak skúsite použiť ten istý deskriptor pre ďalší obchod (alebo inú konfiguráciu BTCPay), dostanete chybu napr.:

- *„This descriptor is already in use“*

- *„BTCPay allows each descriptor to be used only once. Please use a different wallet/descriptor.“*

**Čo to znamená:**

- Každý obchod v Satfluxe potrebuje **vlastný** deskriptor.

- Ak prevádzkujete viac obchodov s Aqua, použite pre každý obchod **inú Aqua peňaženku** (a tým aj iný deskriptor), alebo ak Aqua podporuje viac exportovateľných deskriptorov, vytvorte ďalšie.

- Ten istý deskriptor nemôžete použiť viackrát naprieč obchodmi na tom istom BTCPay serveri.

Ďalšie informácie o Aqua a Boltz sú v návode na deskriptor v aplikácii (napr. v Dokumentácii). Porovnanie Blink a Aqua je v článku Výber peňaženky. Pre umiestnenie Pripojenia peňaženky v aplikácii pozri Prehľad Pripojenia peňaženky.
