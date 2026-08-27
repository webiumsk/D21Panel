---
title: "Nemôžeme pristupovať k vaším prostriedkom ani ich presúvať"
category: security
order: 1
meta_description: "Plain-language guarantee: Blink = read+receive only, no send; Aqua = watch-only descriptor, no private keys. We never have spend authority. Funds stay in your wallet."
---

Vaše prostriedky zostávajú vo vašej peňaženke. Satflux ani BTCPay nikdy nemajú možnosť ich míňať, vyberať ani presúvať.

**Blink: iba read a receive, žiadny send (write)**

Pri pripojení Blink používate API kľúč s oprávneniami **iba read a receive**. Nepoužívame — a vy by ste nemali udeľovať — **send** ani **withdraw (write)**.

- **Môžeme**: vytvárať adresy a Lightning faktúry a prijímať platby do vašej Blink peňaženky.

- **Nemôžeme**: odosielať, vyberať ani presúvať žiadne z vašich prostriedkov.

Takže nikdy nemáme oprávnenie míňať. Všetky prijaté prostriedky zostávajú v peňaženke, ktorú kontrolujete v Blink.

**Aqua: watch-only deskriptor, žiadne súkromné kľúče**

Pri pripojení Aqua nám zadáte **watch-only výstupný deskriptor**. Obsahuje len verejné informácie (napr. rozšírené verejné kľúče). **Neobsahuje** súkromné kľúče.

- **Môžeme**: odvodzovať adresy a prijímať platby do peňaženky, ktorú kontrolujete v Aqua.

- **Nemôžeme**: míňať, podpisovať transakcie ani presúvať prostriedky — súkromné kľúče nikdy nevidíme ani neukladáme.

Takže nikdy nemáme oprávnenie míňať. Všetky prijaté prostriedky zostávajú v peňaženke, ktorú kontrolujete v Aqua.

**Zhrnutie**

- **Blink** — Iba read + receive; žiadny send. Prostriedky zostávajú vo vašej Blink peňaženke.

- **Aqua** — Watch-only deskriptor; žiadne súkromné kľúče. Prostriedky zostávajú vo vašej Aqua peňaženke.

Nikdy nemáme oprávnenie míňať. Vaše prostriedky zostávajú vo vašej peňaženke.
