---
title: "Blink: formát connection stringu a riešenie problémov"
category: wallet-connection
order: 3
meta_description: "Exact format, common mistakes (wrong permissions, missing fields). What “read and receive” means in Blink. How to revoke or rotate the key if needed."
---

Táto stránka popisuje **presný formát** Blink connection stringu, **časté chyby**, čo v Blink znamená **„read a receive“** a ako **odvolať alebo obmeniť** API kľúč v prípade potreby.

---

**Presný formát**

Connection string je jeden riadok, **oddelený bodkočiarkami**, **bez medzier** okolo = alebo ;. Poradie platní.

type=blink;server=URL;api-key=KEY;wallet-id=ID

**Povinné polia:**

| Časť | Popis | Príklad |
|---|---|---|
| type | Musí byť presne blink (malé písmená). | type=blink |
| server | URL Blink GraphQL API. Použite HTTPS. | server=[https://api.blink.sv/graphql](https://api.blink.sv/graphql) |
| api-key | Váš Blink API kľúč (napr. začína na blink_). | api-key=blink_xxxxxxxx |
| wallet-id | ID Blink peňaženky, pre ktorú je kľúč. | wallet-id=abc123 |

**Celý príklad:**

type=blink;server=[https://api.blink.sv/graphql;api-key=blink_xxxxxxxx;wallet-id=your-wallet-id](https://api.blink.sv/graphql;api-key=blink_xxxxxxxx;wallet-id=your-wallet-id)

- **Nepridávajte** medzery, zalomenia riadkov ani úvodzovky.

- Medzi časťami používajte **bodkočiarky** (;), nie čiarky ani nové riadky.

- Všetky štyri časti musia byť prítomné. Ak niečo chýba, validácia zlyhá.

---

**Časté chyby**

- **Nesprávne alebo chýbajúce oprávnenia** — API kľúč musí mať **iba read a receive**. Ak má **send** alebo **withdraw**, my ho stále používame len na prijímanie; pre bezpečnosť a princíp je lepšie vytvoriť kľúč len s read+receive. Ak kľúč nemá oprávnenie receive, BTCPay nebude môcť prijímať platby a môžu sa objaviť chyby pri vytváraní alebo platbe faktúr.

- **Chýbajúce polia** — Zabudnutie na type=blink, server, api-key alebo wallet-id spôsobí „Invalid connection string format“ alebo podobnú chybu. Skontrolujte, že všetky štyri časti sú prítomné a správne napísané (napr. wallet-id s pomlčkou, nie wallet_id).

- **Medzery alebo extra znaky** — Medzery pred/za = alebo ;, alebo vlepenie reťazca s koncom riadku alebo úvodzovkami, môže pokaziť parsovanie. Vkladajte jeden čistý riadok bez medzier na začiatku a na konci.

- **Nesprávna URL servera** — Použite [https://api.blink.sv/graphql](https://api.blink.sv/graphql). HTTP (bez „s“) alebo preklep v hoste alebo ceste môže spôsobiť zlyhanie pripojenia.

- **Expirovaný alebo odvolaný kľúč** — Ak bol kľúč v Blink dashboarde odvolaný alebo zmazaný, pripojenie prestane fungovať. Vytvorte nový kľúč a v Satfluxe aktualizujte connection string (pozri „Odvolanie alebo obmena kľúča“ nižšie).

---

**Čo v Blink znamená „read a receive“**

Keď hovoríme, že API kľúč by mal mať **iba read a receive**:

- **Read** — Kľúč môže čítať stav peňaženky (adresy, zostatok, zoznam transakcií). BTCPay to používa na to, kam prijímať a ako spárovať prichádzajúce platby.

- **Receive** — Kľúč môže vytvárať nové adresy a prijímať platby (on-chain aj Lightning). Oprávnenie **send** ani **withdraw** nie je potrebné ani žiaduce.

Teda: môžeme **vytvárať adresy a prijímať** prostriedky do vašej Blink peňaženky. **Nemôžeme** odosielať, vyberať ani presúvať prostriedky. Plnú kontrolu nad míňaním máte vy; kľúč je len na prijímanie.

---

**Odvolanie alebo obmena kľúča**

Ak bol kľúč kompromitovaný alebo ho chcete z bezpečnostných dôvodov obmeniť:

1. **V Blink dashboarde** 1:

- Otvorte peňaženku a prejdite na API kľúče ( alebo podobné).

- **Odvoľte** (zmažte) starý kľúč a/alebo **vytvorte nový** API kľúč s oprávneniami read a receive.

1. **V paneli Satflux:**

- Otvorte obchod → **Pripojenie peňaženky** (LN Wallet Connection).

- Nahraďte starý connection string novým, ktorý používa nový API kľúč (rovnaký formát: type=blink;server=...;api-key=NOVÝ_KĽÚČ;wallet-id=...).

- Uložte. Ak vaša inštancia používa manuálny krok, support môže musieť nové pripojenie znova aplikovať v BTCPay; potom budú nové faktúry používať nový kľúč.

Kým nie je nový reťazec uložený v Satfluxe a (ak platí) aplikovaný v BTCPay, starý kľúč sa stále používa. Po odvolaní starého kľúča v Blink pripojenie prestane fungovať, kým nebude zavedený nový — preto je často jednoduchšie najprv vytvoriť nový kľúč, aktualizovať Satflux (a BTCPay ak treba), a až potom odvolať starý kľúč.

Postup nastavenia je v článku Blink: získanie connection stringu pre BTCPay.
