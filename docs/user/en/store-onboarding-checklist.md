---
title: "Store onboarding checklist"
category: getting-started
order: 5
meta_description: "What the checklist is (connect wallet, enable Lightning, test invoice, etc.). Blink vs Aqua checklist differences. Where to find it in the UI (resources/js/pages/stores/Checklist.vue, app/Services/StoreChecklistService.php)."
---

After you create a store, a **wallet onboarding checklist** helps you finish wallet setup and confirm that the store can receive payments. The steps are the **same** whether you use Blink or Aqua: you enter your credential, create a Point of Sale (PoS) app, and test a payment. You work through the list and mark steps complete yourself; the checklist is a guide, not an automatic check.

---

**What the checklist is**

The steps are:

1. **Enter your wallet credential** — In **Wallet connection** (store → LN Wallet Connection), add your Blink connection string or your Aqua output descriptor. All configuration is done in the Satflux panel; you do not need to enable plugins or configure BTCPay yourself.
2. **Create a Point of Sale (PoS)** — Create a PoS app for the store. You can use it to create invoices and accept in-person or online payments.
3. **Test a payment** — Create a test invoice (e.g. from the PoS), pay it **from a different wallet** than the one that receives (e.g. from another phone or app), and confirm that the funds arrive in your receiving wallet.

When testing, you must pay from a **different** wallet than the one connected to the store; otherwise you are not really testing the flow. Once the payment shows up in your wallet, you can mark the step complete.

---

**Where to find it in the UI**

- **After creating a store** — You are taken to the **Next steps** page. Use the link **“View Onboarding Checklist”** to open the checklist for that store.

- **From the store** — Open the store and go to the **Wallet onboarding** / **Checklist** page. The URL is: /stores/{store-id}/checklist.

The checklist page is titled **“Wallet onboarding”**. You can tick off each step when you have done it; progress is saved. All steps are required for a complete setup.

For how to add your credential, see Wallet connection overview. For Blink vs Aqua, see Choosing your wallet.
