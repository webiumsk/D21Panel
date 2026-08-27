---
title: "Čo ukladáme a ako to chráníme"
category: security
order: 2
meta_description: "Encrypted storage of connection string/descriptor. Who can see what (you, support if needed). No plaintext secrets in logs or to third parties."
---

Ukladáme len to, čo je potrebné na prepojenie vášho obchodu s vašou peňaženkou v BTCPay: váš **connection string** (Blink) alebo **výstupný deskriptor** (Aqua). Táto hodnota sa považuje za tajomstvo a je chránená tak, aby ste mali kontrolu vy a my sme ju zbytočne neprezrádzali.

---

**Šifrované uloženie**

- Vaše prihlasovacie údaje (connection string alebo deskriptor) sa v databáze ukladajú v **šifrovanej** podobe.

- Šifrovanie používa tajný kľúč aplikácie. Rovnaká hodnota sa v databáze nikdy neukladá v čistom texte.

- Dešifruje sa len keď ju systém alebo support potrebuje použiť (napr. na konfiguráciu BTCPay alebo na dočasné zobrazenie supportu, aby ju mohol vložiť do BTCPay). Pri bežnom načítaní stránok ani vo vašom rozhraní sa neprezentuje v plnej podobe.

Teda: ukladáme jeden secret na pripojenie peňaženky a ukladáme ho šifrovane.

---

**Kto čo vidí**

- **Vy (obchodník)** — V paneli Satflux môžete pridať, nahradiť alebo odstrániť connection string alebo deskriptor. Pri **úprave alebo nahradení** pripojenia vám formulár môže zobraziť **celú** hodnotu, aby ste ju mohli zmeniť. Keď len prezeráte pripojenie (neupravujete), zvyčajne vidíte **maskovanú** nápovedu (napr. prvých a posledných pár znakov). Ak chcete **znovu zobraziť** uložený secret (napr. na skopírovanie), musíte zadať svoje **heslo** — celý secret bez tohto kroku nezobrazujeme.

- **Support (ak treba)** — Keď pripojenie peňaženky vyžaduje manuálnu konfiguráciu v BTCPay, support môže dočasne **zobraziť** secret v čistom texte (workflow supportu môže vyžadovať overenie). Tento prístup slúži len na konfiguráciu vášho obchodu. Support secret nepoužíva na nič iné a my ho neposkytujeme tretím stranám.

- **Nikto iný** — Connection string ani deskriptor neposielame tretím stranám. Do logov ho v čistom texte nezapisujeme.

---

**Žiadne tajomstvá v čistom texte v logoch ani tretím stranám**

- **Nezapisujeme** celý connection string ani deskriptor do **logov**. V logoch môžu byť necitlivé metadáta (napr. že pripojenie bolo uložené alebo jeho typ), nie samotný secret.

- Vaše prihlasovacie údaje **neposielame** **tretím stranám**. Používajú sa len v rámci nášho systému a v prípade potreby sú dočasne zobrazené supportu na dokončenie konfigurácie BTCPay. Neposkytujeme ich externým službám, analytike ani nikomu inému.

Ak pripojenie peňaženky zmeníte alebo odstránite, uložený secret sa podľa toho aktualizuje alebo zmaže. Šifrovanie platí po celú dobu, kedy je secret uložený.

Ako s údajom nakladáme (iba prijímanie a sledovanie, nikdy míňanie) je popísané v článku Nemôžeme pristupovať k vaším prostriedkom ani ich presúvať. Kde sa údaj v aplikácii používa, je v článku Prehľad Pripojenia peňaženky.
