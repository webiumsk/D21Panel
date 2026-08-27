---
title: Descriptory Aqua a Bull Bitcoin
category: wallet-connection
order: 4
meta_description: Pripojte Aqua alebo Bull Bitcoin vložením watch-only output descriptora - plne nekustodiálne.
---

# Descriptory Aqua a Bull Bitcoin

**Aqua** aj **Bull Bitcoin** sa pripájajú vložením **watch-only output descriptora**. Je to plne nekustodiálne: descriptor umožní BTCPay sledovať platby, ale neobsahuje **žiadne privátne kľúče** - tie ostávajú v peňaženke. Satflux odmietne čokoľvek, čo obsahuje privátne kľúče.

Pre Aqua je jednoduchšia QR metóda - viď [Pripojenie Aqua cez SamRock](/documentation/connect-aqua-with-samrock). Bull Bitcoin používa descriptorovú metódu opísanú tu.

## Kde získať descriptor

- **Aqua**: Nastavenia → Boltz → exportujte watch-only output descriptor. Vyzerá ako `ct(slip77(...),elsh(wpkh(...)))`.
- **Bull Bitcoin**: nastavenia peňaženky → exportujte watch-only descriptor. Bull používa formát `elwpkh(...)`.

Satflux ich rozlíši automaticky podľa tvaru descriptora.

## Kroky

1. Otvorte **Pripojenie peňaženky** a zvoľte tab **Advanced (connection strings)**.
2. Vložte descriptor do poľa. Satflux rozpozná, či ide o Aqua alebo Bull Bitcoin, a overí formát.
3. Uložte. Satflux nastaví BTCPay a zapne Lightning cez **Boltz** swap (Lightning cez Liquid).

## Riešenie problémov

- **„Obsahuje privátny kľúč“** - exportovali ste zlý reťazec. Exportujte **watch-only** descriptor, nikdy nie seed ani `xprv`/`zprv`.
- **„Už sa používa“** - descriptor môže byť pripojený len k jednému obchodu. Najprv ho inde odpojte.
- **Zlý formát** - Aqua descriptory sú `elsh(wpkh(...))`, Bull sú `elwpkh(...)`. Skontrolujte, že ste skopírovali celý reťazec bez prerušenia.
- **Lightning zatiaľ nefunguje** - descriptorové pripojenia zapínajú Lightning cez Boltz; skontrolujte indikátor pripravenosti Boltz na obchode. Chvíľu môže trvať, kým bude pripravený.

Na neskoršiu zmenu alebo odpojenie viď [Zrušenie alebo zmena údajov peňaženky](/documentation/revoking-or-changing-wallet-credentials).
