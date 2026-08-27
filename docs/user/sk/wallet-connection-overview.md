---
title: "Prehľad Pripojenia peňaženky"
category: wallet-connection
order: 1
meta_description: "Zadané údaje sa ukladajú šifrované a používajú sa len na prijímanie a sledovanie; platforma nikdy nemíňa vaše prostriedky."
---

Pripojenie peňaženky (Wallet connection) je miesto, kde pridáte alebo zmeníte Lightning peňaženku (Blink alebo Aqua), ktorú váš obchod používa na prijímanie platieb. Zadané údaje sa ukladajú šifrované a používajú sa len na prijímanie a sledovanie; platforma nikdy nemíňa vaše prostriedky.

**Kde to nájdete**

- **Z obchodu:** Otvorte obchod (napr. z **Obchody** v hlavnej ponuke), v **bočnom paneli obchodu** kliknite na **LN Wallet Connection** (alebo **Pripojenie peňaženky**). Adresa je /stores/{id-obchodu}/wallet-connection.

- **Z dashboardu obchodu:** Ak nie je peňaženka nakonfigurovaná, prehľad obchodu zobrazí upozornenie a odkaz na **Pripojenie peňaženky**.

- **Zo sprievodcu nastavením:** Po vytvorení obchodu sú v rámci „Ďalších krokov“ / checklistu odkaz na **Pripojenie peňaženky** na dokončenie nastavenia peňaženky.

Používatelia supportu a administrátori majú tiež oblasť **Wallet Connections** (napr. v rámci Support), kde spracúvajú žiadosti o pripojenie, ktoré vyžadujú manuálnu konfiguráciu v BTCPay.

**Čo ukladáme**

Ukladáme len to, čo je potrebné na prepojenie vášho obchodu s vašou peňaženkou v BTCPay:

- **Typ** — Či ide o Blink alebo Aqua (deskriptor).

- **Vaše prihlasovacie údaje** — Pri Blink: connection string (URL servera, API kľúč, ID peňaženky). Pri Aqua: výstupový deskriptor (watch-only; bez súkromných kľúčov). Táto hodnota sa v databáze ukladá **šifrovaná**. Dešifruje sa len keď náš systém alebo support konfiguruje BTCPay (alebo keď support dočasne zobrazí údaj na vloženie do BTCPay). V UI sa celý secret nikdy nezobrazuje; môžete ho len nahradiť alebo vidieť maskovanú nápovedu.

Súkromné kľúče neukladáme. Pri Aqua je deskriptor watch-only. Pri Blink connection string umožňuje serveru vytvárať adresy a prijímať vo vašom mene; nedáva nám ani BTCPay možnosť míňať.

**Len prijímanie a sledovanie — nikdy nemíňame**

Údaje k peňaženke sa používajú **iba** na to, aby BTCPay Server mohol:

- **Prijímať** — Vytvárať adresy a Lightning faktúry a prijímať platby do peňaženky, ktorú kontrolujete vy.

- **Sledovať** — (Pri Aqua/deskriptore) Odvodzovať a sledovať adresy, aby server mohol spárovať prichádzajúce platby.

Satflux ani BTCPay nikdy nedostanú možnosť **míňať** z vašej peňaženky. Nedržíme súkromné kľúče; neodosielame transakcie. Kedy a kam sa prostriedky presunú, kontrolujete vy.

Postup nastavenia je v článkoch Vytvorenie prvého obchodu a Výber peňaženky: Blink vs Aqua.
