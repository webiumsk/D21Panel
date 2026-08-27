---
title: "Blink: získanie connection stringu pre BTCPay"
category: wallet-connection
order: 2
meta_description: "Step-by-step: open Blink → create or use an API key with read and receive only (no send). Format: type=blink;server=...;api-key=...;wallet-id=.... Where to paste it in Satflux panel. Emphasise: read+receive only, so we cannot move your funds."
---

Ak chcete pripojiť Blink peňaženku k obchodu v Satfluxe, potrebujete **connection string**. Ten BTCPay povie, ako komunikovať s Blink cez API kľúč s oprávneniami **iba na čítanie a prijímanie** — bez odosielania. Môžeme teda vytvárať adresy a prijímať platby vo vašom mene; **nemôžeme** presúvať ani míňať vaše prostriedky.

---

**Krok 1 — Otvorte Blink a získajte údaje na pripojenie**

1. Prejdite do **Blink dashboardu**: [https://dashboard.blink.sv/](https://dashboard.blink.sv/)

1. Prihláste sa (alebo si vytvorte účet a peňaženku).

1. Vytvorte pre túto peňaženku **API kľúč** alebo použite existujúci.

1. Uistite sa, že API kľúč má **iba** oprávnenia **read** a **receive**. **Nepridávajte** oprávnenie **send** ani **withdraw**. Kľúč tak bude môcť len vytvárať adresy a prijímať platby; nebude môcť míňať.

1. Potrebujete:

- **Server URL** — zvyčajne [https://api.blink.sv/graphql](https://api.blink.sv/graphql)

- **API kľúč** — kľúč, ktorý ste vytvorili (často začína na blink_)

- **Wallet ID** — ID peňaženky, pre ktorú je kľúč určený

---

**Krok 2 — Zostavte connection string**

Použite tento formát (oddelené bodkočiarkou, bez medzier):

type=blink;server=[https://api.blink.sv/graphql;api-key=VÁŠ_API_KĽÚČ;wallet-id=VÁŠ_WALLET_ID](https://api.blink.sv/graphql;api-key=VÁŠ_API_KĽÚČ;wallet-id=VÁŠ_WALLET_ID)

Príklad (s náhradnými hodnotami):

type=blink;server=[https://api.blink.sv/graphql;api-key=blink_xxxxxxxx;wallet-id=your-wallet-id](https://api.blink.sv/graphql;api-key=blink_xxxxxxxx;wallet-id=your-wallet-id)

Nahraďte VÁŠ_API_KĽÚČ a VÁŠ_WALLET_ID skutočnými hodnotami. Zachovajte poradie: type=blink, potom server=..., api-key=..., wallet-id=....

---

**Krok 3 — Vložte connection string do panelu Satflux**

Connection string môžete zadať na jednom z dvoch miest:

- **Pri vytváraní obchodu** — V sprievodcovi vytvorením obchodu v **Kroku 2 (Typ peňaženky)**:

- Zvoľte **Blink**.

- Do poľa **Connection string**, ktoré sa zobrazí, vložte celý reťazec.

- Pokračujte do Kroku 3 a vytvorte obchod.

- **Keď už obchod existuje** — Otvorte obchod, v bočnom paneli obchodu prejdite na **Pripojenie peňaženky** (alebo **LN Wallet Connection**). Do formulára vložte connection string a uložte.

Hodnota sa ukladá **šifrovaná**. Používa sa len na to, aby BTCPay mohol vytvárať adresy a prijímať platby; nikdy nemáme možnosť míňať z vašej Blink peňaženky.

---

**Dôležité: iba read + receive**

Connection string používa API kľúč s oprávneniami **iba read a receive**. To znamená:

- BTCPay (a tým aj Satflux) **môže**: vytvárať adresy, vytvárať Lightning faktúry a prijímať platby do vašej Blink peňaženky.

- BTCPay (ani Satflux) **nemôže**: odosielať, vyberať ani presúvať vaše prostriedky.

Plnú kontrolu nad míňaním máte vy. Kľúč používame len na prijímanie vo vašom mene.

Prehľad toho, kde v aplikácii nájdete Pripojenie peňaženky a čo ukladáme, je v článku Prehľad Pripojenia peňaženky. Porovnanie Blink a Aqua je v článku Výber peňaženky.
