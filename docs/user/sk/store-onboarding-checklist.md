---
title: "Checklist nastavenia obchodu"
category: after-setup
order: 1
meta_description: "What the checklist is (connect wallet, enable Lightning, test invoice, etc.). Blink vs Aqua checklist differences. Where to find it in the UI (resources/js/pages/stores/Checklist.vue, app/Services/StoreChecklistService.php)."
---

Po vytvorení obchodu vám **checklist nastavenia peňaženky** (wallet onboarding) pomôže dokončiť pripojenie peňaženky a overiť, že obchod prijíma platby. Kroky sú **rovnaké** pri Blink aj pri Aqua: zadáte údaje peňaženky, vytvoríte Point of Sale (PoS) aplikáciu a otestujete platbu. Kroky si prejdete a označíte ako dokončené sami; checklist je návod, nie automatická kontrola.

---

**Čo checklist obsahuje**

Kroky sú:

1. **Zadať údaje peňaženky** — V **Pripojení peňaženky** (obchod → LN Wallet Connection) pridajte Blink connection string alebo Aqua výstupný deskriptor. Všetka konfigurácia sa robí v paneli Satflux; nemusíte sami povoľovať pluginy ani nastavovať BTCPay.
2. **Vytvoriť Point of Sale (PoS)** — Vytvorte PoS aplikáciu pre obchod. Môžete ju použiť na vytváranie faktúr a prijímanie platieb na mieste alebo online.
3. **Otestovať platbu** — Vytvorte testovaciu faktúru (napr. z PoS), zaplaťte ju **z inej peňaženky** než tej, ktorá prijíma (napr. z iného telefónu alebo aplikácie), a overte, že prostriedky dorazia do vašej prijímajúcej peňaženky.

Pri testovaní musíte platiť **z inej** peňaženky než z tej pripojenej k obchodu; inak netestujete skutočný tok. Keď platba dorazí do vašej peňaženky, krok môžete označiť za dokončený.

---

**Kde to nájdete v aplikácii**

- **Po vytvorení obchodu** — Ste presmerovaní na stránku **Ďalšie kroky**. Použite odkaz **„View Onboarding Checklist“** na otvorenie checklistu pre daný obchod.

- **Z obchodu** — Otvorte obchod a prejdite na stránku **Wallet onboarding** / **Checklist**. URL: /stores/{id-obchodu}/checklist.

Stránka checklistu má nadpis **„Wallet onboarding“**. Každý krok môžete označiť za dokončený; stav sa ukladá. Všetky kroky sú potrebné na úplné nastavenie.

Ako pridať údaje peňaženky: Prehľad Pripojenia peňaženky. Blink vs Aqua: Výber peňaženky.
