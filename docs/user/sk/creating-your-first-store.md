---
title: "Vytvorenie prvého obchodu"
category: getting-started
order: 3
meta_description: "Nový obchod v Satfluxe vytvoríte v troch krokoch: základné údaje, typ peňaženky a potvrdenie. Obchod sa potom vytvorí v BTCPay Serveri a zobrazí sa krátky checklist, aby ste mohli dokončiť nastavenie peňaženky a otestovať platbu."
---

Nový obchod v Satfluxe vytvoríte v troch krokoch: základné údaje, typ peňaženky a potvrdenie. Obchod sa potom vytvorí v BTCPay Serveri a zobrazí sa krátky checklist, aby ste mohli dokončiť nastavenie peňaženky a otestovať platbu.

**Krok 1 — Základné údaje**

- **Názov obchodu** — Názov vášho obchodu (napr. „Hlavný obchod“, „E-shop“).

- **Predvolená mena** — Mena pre ceny a sumy (napr. EUR, USD, BTC, SATS).

- **Časové pásmo** — Používa sa pre faktúry a reporty.

- **Predvolený zdroj ceny** — Burza alebo zdroj pre prevod fiatu na BTC (napr. pre zobrazenie súm). Podľa predvolenej meny sa zvolí vhodná predvolená hodnota; môžete ju zmeniť tu alebo neskôr v nastaveniach obchodu.

Keď sú všetky polia vyplnené, kliknite na **Ďalší krok**.

**Krok 2 — Výber typu peňaženky**

Zvoľte, ako bude obchod prijímať Lightning (a on-chain) platby:

- **Blink** — Rýchle nastavenie, vhodné na rýchly štart. Peňaženku Blink pripojíte v tomto kroku alebo neskôr (napr. cez connection string). Porovnanie Blink a Aqua nájdete v článku Výber peňaženky.

- **Aqua (Aqua + Boltz)** — Nekustodiálne: vaše kľúče, Liquid + Lightning. Používa sa plugin Boltz v BTCPay. Nastavenie môžete urobiť v tomto kroku alebo dokončiť neskôr. Detaily sú v článkoch Výber peňaženky a Pripojenie peňaženky.

V tomto kroku môžete voliteľne zadať **connection string** (Blink) alebo **descriptor** (Aqua). Ak to preskočíte, pripojenie dokončíte neskôr v **Pripojenie peňaženky** a podľa checklistu po vytvorení obchodu.

Kliknutím na **Späť** zmeníte základné údaje, alebo na **Ďalší krok** pokračujete.

**Krok 3 — Potvrdenie**

Skontrolujte názov obchodu, menu, časové pásmo, zdroj ceny a typ peňaženky. Kliknutím na **Vytvoriť obchod** sa obchod vytvorí v BTCPay. Potom budete presmerovaní na stránku „Ďalšie kroky“ s odkazom na **checklist nastavenia peňaženky** (napr. pripojiť peňaženku, zapnúť Lightning, otestovať faktúru).

**Po vytvorení**

- Ak ste v kroku 2 nevyplnili údaje peňaženky, dokončite nastavenie a Lightning v **Pripojenie peňaženky**. Postup je v článku Pripojenie peňaženky.

- Z dashboardu obchodu vytvorte **Point of Sale (PoS)** aplikáciu a podľa potreby **Lightning adresu**.
