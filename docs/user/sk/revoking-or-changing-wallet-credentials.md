---
title: "Odvolanie alebo zmena údajov peňaženky"
category: wallet-connection
order: 7
meta_description: "How to disconnect or update Blink API key / Aqua descriptor. That you can revoke in the wallet app and reconnect with a new credential."
---

**Odvolanie alebo zmena údajov peňaženky**

**Zmeniť** údaje peňaženky (Blink connection string alebo Aqua deskriptor) môžete kedykoľvek tak, že v paneli Satflux zadáte nové. Akciu „odpojiť“ (disconnect) neponúkame: v BTCPay môžete len **nahradiť** pripojenie novým údajom, nie ho odstrániť bez náhrady. Ak teda chcete prestať používať danú peňaženku, odvoláte alebo zmeníte ju v aplikácii peňaženky a potom k obchodu **pripojíte nový** údaj.

---

**Potvrdenie zmeny pripojenej peňaženky**

Nahradenie peňaženky, ktorá je už **pripojená**, je citlivá zmena, preto ju Satflux nechá potvrdiť ešte pred otvorením formulára:

1. Otvorte obchod → **Pripojenie peňaženky** a stlačte **Zmeniť pripojenie**.
2. Zadajte heslo k účtu (účty prihlasované obnovovacou frázou tento krok preskočia).
3. Na adresu vášho účtu pošleme **6-miestny kód**. Zadajte ho do formulára - kód platí 10 minút a nový si môžete vyžiadať po krátkom odpočte.
4. Formulár sa otvorí s predvyplneným aktuálnym údajom. Nahraďte ho a uložte. Potvrdenie platí 15 minút; ak vyprší, Satflux si pred uložením vyžiada nový kód.

Kým kód nepotvrdíte, na obchode sa nič nemení. To isté potvrdenie platí aj pre opätovné párovanie cez SamRock a prepnutie Lightning obchodu na Cashu. **Hosťovský účet** nemá e-mail, na ktorý by kód prišiel, preto ho treba najprv povýšiť pridaním e-mailovej adresy (pozri [Vytvorte si účet](/documentation/create-account)). Prvé pripojenie peňaženky k novému obchodu kód nevyžaduje.

---

**Blink: odvolať alebo obmeniť API kľúč, potom aktualizovať v Satfluxe**

1. **V Blink dashboarde** 1: Odvoľte (zmažte) starý API kľúč a/alebo vytvorte nový s oprávneniami **read a receive**.

1. **V Satfluxe:** Otvorte obchod → **Pripojenie peňaženky** (LN Wallet Connection). Vo formulári **nahraďte** starý connection string novým: type=blink;server=...;api-key=NOVÝ_KĽÚČ;wallet-id=.... Uložte.

1. **V BTCPay:** Pripojenie je to, ktoré je pre obchod aktuálne nakonfigurované. „Odpojiť“ neponúkame - **zmeníte** ho tak, že v Satfluxe odošlete nový connection string. Ak vaša konfigurácia používa manuálny krok, support môže musieť nový reťazec aplikovať v BTCPay. Potom obchod používa nový kľúč.

Teda: v Blink odvoláte alebo obmeníte kľúč, potom v Satfluxe (a podľa potreby v BTCPay) znova pripojíte obchod s novým údajom.

---

**Aqua: použiť iný deskriptor, potom aktualizovať v Satfluxe**

1. **V Aqua:** Ak chcete prestať používať aktuálny deskriptor, exportujte **nový** watch-only deskriptor (napr. z inej peňaženky alebo inej derivácie). BTCPay povoľuje každý deskriptor len raz na inštanciu, takže „nový“ údaj znamená iný deskriptor (iná peňaženka alebo iný export).

1. **V Satfluxe:** Otvorte obchod → **Pripojenie peňaženky**. Vo formulári **nahraďte** starý deskriptor novým a uložte.

1. **V BTCPay:** Rovnako ako pri Blink neexistuje „odpojiť“ - konfiguráciu Lightning/peňaženky **nahradíte** novým deskriptorom. Ak to vyžaduje vaša konfigurácia, support môže musieť zmenu v BTCPay aplikovať.

Teda: v aplikácii peňaženky odvoláte alebo zmeníte údaj (nový kľúč alebo nový deskriptor), potom v Satfluxe odošlete nový údaj a tým obchod znova pripojíte.

---

**Zhrnutie**

- V Satfluxe ani v BTCPay **neponúkame** „odpojiť“ - len **zmeniť/nahradiť** pripojenie.

- Ak chcete prestať používať údaj: odvolajte alebo zmeňte ho v **Blink** alebo **Aqua**, potom v Satfluxe zadajte **nový** connection string alebo deskriptor, aby obchod používal nový údaj.

- Obchod má vždy jedno aktívne pripojenie peňaženky; zmeníte ho tak, že ho nahradíte iným.

Formát a obmena Blink kľúča: Blink: formát connection stringu a riešenie problémov. Kde vložiť údaj: Prehľad Pripojenia peňaženky.
