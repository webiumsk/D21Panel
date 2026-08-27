---
title: "Výber peňaženky: Blink vs Aqua"
category: getting-started
order: 3
meta_description: "When to use Blink (speed, reliability, read+receive API). When to use Aqua (Boltz, descriptor-based, non-custodial). No private keys or spend capability given to the platform."
---

Pri vytváraní obchodu v Satfluxe zvolíte, ako bude prijímať Bitcoin a Lightning: **Blink** alebo **Aqua** (s pluginom Boltz). Obe možnosti fungujú s BTCPay Serverom; líšia sa tým, ako je peňaženka pripojená a kto kontroluje kľúče.

**Dôležité: vaše kľúče, vaše mince**

Pri Blink aj pri Aqua **Satflux ani BTCPay nedostanú žiadne súkromné kľúče ani možnosť míňať**. Platforma nikdy nemá možnosť presunúť vaše prostriedky. Pripájate vlastnú peňaženku; faktúry sa vytvárajú v BTCPay a platby prijíma peňaženka, ktorú kontrolujete vy.

---

**Kedy použiť Blink**

Blink je Lightning peňaženková služba, ktorú k BTCPay pripájate cez **read + receive** API: server môže vytvárať adresy a prijímať platby vo vašom mene, ale nemôže míňať. Pripojenie sa robí connection stringom (URL servera, API kľúč, ID peňaženky), ktorý získate z Blink dashboardu.

- **Rýchlosť a jednoduchosť** — Nastavenie je rýchle. Po zadaní connection stringu v sprievodcovi vytvorením obchodu alebo v Pripojení peňaženky je Lightning po krátkom checkliste pripravený (zapnúť Lightning, otestovať faktúru).

- **Spoľahlivosť** — Blink je určený pre obchodníkov a bežne sa používa s BTCPay pre platby na mieste aj online.

- **Vhodné ak** — Chcete ísť do prevádzky rýchlo, preferujete spravovaný Lightning backend a nevadí vám použiť Blink API len na prijímanie (žiadne kľúče ani právo míňať neopúšťajú vašu kontrolu).

Postup pripojenia je v článku Pripojenie peňaženky a v krokoch Blink v checkliste obchodu.

---

**Kedy použiť Aqua**

Aqua je nekustodiálna peňaženka (Liquid a Lightning). K BTCPay ju pripájate cez plugin **Boltz** a **descriptor**: watch-only výstupový deskriptor, ktorý popisuje adresy vašej peňaženky. Deskriptor neobsahuje súkromné kľúče, takže BTCPay (ani Satflux) nikdy nevidia ani nedržia nič, čo by mohlo míňať vaše prostriedky.

- **Nekustodiálne** — Kľúče držíte v Aqua. Platforma vidí len verejné/deskriptorové informácie a môže vytvárať adresy a prijímať platby; nemôže míňať.

- **Založené na deskriptore** — Z Aqua exportujete deskriptor a vložíte ho do sprievodcu obchodom alebo do Pripojenia peňaženky. BTCPay ho používa na odvodenie adries a spolu s Boltz na Lightning.

- **Vhodné ak** — Chcete plnú self-custody (vaše kľúče, vaše mince), nevadí vám trochu viac nastavenia (plugin Boltz, deskriptor) a chcete Liquid + Lightning v jednej peňaženke.

Detaily nastavenia sú v článku Pripojenie peňaženky a v checkliste Aqua/Boltz po vytvorení obchodu.

---

**Stručné porovnanie**

|  | Blink | Aqua (Boltz) |
|---|---|---|
| **Kustódia** | Kľúče drží Blink; vy používate read+receive API | Kľúče držíte v Aqua; deskriptor je watch-only |
| **Nastavenie** | Connection string z Blink dashboardu | Deskriptor z Aqua + plugin Boltz v BTCPay |
| **Výhoda** | Rýchlosť, spoľahlivosť, minimum nastavenia | Nekustodiálne, vaše kľúče, Liquid + Lightning |

V oboch prípadoch **platforma nedostane žiadne súkromné kľúče ani možnosť míňať**. Výber Blink alebo Aqua je o tom, kto drží kľúče a koľko nastavenia chcete, nie o tom, že by ste dôverovali Satfluxu alebo BTCPay v možnosti míňať.
