---
title: Pripojenie cez NWC (Nostr Wallet Connect)
category: wallet-connection
order: 6
meta_description: Pripojte vlastný Lightning uzol cez Nostr Wallet Connect pairing string, napr. z Alby Hub.
---

# Pripojenie cez NWC (Nostr Wallet Connect)

**NWC** (Nostr Wallet Connect) umožňuje pripojiť vlastnú Lightning peňaženku alebo uzol cez pairing string - napríklad zo samohostovaného **Alby Hub**. Je určené pre pokročilých, samohostujúcich používateľov.

## Čo potrebujete

- Peňaženku, ktorá vydáva NWC pripojenia (napr. vlastný **Alby Hub**).
- NWC pairing string, ktorý vyzerá takto:

```text
nostr+walletconnect://…?relay=…&secret=…
```

## Kroky

1. Vo vašej NWC peňaženke (napr. Alby Hub → Connections → Add Connection) vytvorte pripojenie a skopírujte reťazec `nostr+walletconnect://…`.
2. V Satfluxe otvorte **Pripojenie peňaženky → Advanced** a vložte reťazec. Satflux ho rozpozná ako NWC.
3. Uložte.

## Poznámky

- Server musí mať k dispozícii **BTCPay Nostr plugin**, aby NWC pripojenia fungovali.
- NWC pairing stringy z **Cashu ecash peňaženiek** (napr. Minibits) sa tu neprijímajú - tie idú cez [Cashu](/documentation/accept-cashu-ecash).
- Väčšina obchodníkov NWC nepotrebuje. Ak chcete len rýchlo prijímať Lightning, použite [akúkoľvek Lightning adresu](/documentation/connect-with-any-lightning-address) alebo [Aqua cez SamRock](/documentation/connect-aqua-with-samrock).
