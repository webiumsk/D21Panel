<?php

namespace App\Services\Invoicing;

use App\Models\AuditLog;
use App\Models\CompanySlotPurchase;
use App\Models\User;
use App\Services\BtcPay\InvoiceService;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * One-off purchases of extra invoicing company slots (Pro only).
 * A slot is a user-level counter added on top of the plan's included
 * company count - it is never tied to a specific company row.
 */
class CompanySlotService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected SubscriptionEntitlementService $subscriptionService,
    ) {}

    /**
     * Purchasable packs only (placeholder prices with sats <= 0 are hidden).
     *
     * @return array<int, array{slots: int, sats: int}>
     */
    public function availablePacks(): array
    {
        $packs = [];
        foreach (config('pricing.company_slot_packs', []) as $pack) {
            $slots = (int) ($pack['slots'] ?? 0);
            $sats = (int) ($pack['sats'] ?? 0);
            if ($slots > 0 && $sats > 0) {
                $packs[] = ['slots' => $slots, 'sats' => $sats];
            }
        }

        return $packs;
    }

    /**
     * @return array{slots: int, sats: int}
     */
    public function resolvePack(int $slots): array
    {
        foreach ($this->availablePacks() as $pack) {
            if ($pack['slots'] === $slots) {
                return $pack;
            }
        }

        throw ValidationException::withMessages([
            'pack' => ['Unknown pack size.'],
        ]);
    }

    /**
     * @return array{checkoutLink: string, purchaseId: string, slots: int, price_sats: int}
     */
    public function startPurchase(User $user, int $slots): array
    {
        $max = $this->subscriptionService->maxCompaniesForUser($user);
        if ($max === null) {
            throw ValidationException::withMessages([
                'plan' => ['Your plan already includes unlimited companies.'],
            ]);
        }
        if (! $this->subscriptionService->canUseBusinessInvoicing($user)) {
            throw ValidationException::withMessages([
                'plan' => ['Business invoicing is not available on your plan. Upgrade to Pro first.'],
            ]);
        }

        $pack = $this->resolvePack($slots);
        $storeId = config('services.btcpay.subscription_store_id');
        if (! $storeId) {
            throw ValidationException::withMessages([
                'billing' => ['Company slot billing is not configured.'],
            ]);
        }

        $purchase = CompanySlotPurchase::create([
            'user_id' => $user->id,
            'slots' => $pack['slots'],
            'price_sats' => $pack['sats'],
            'status' => CompanySlotPurchase::STATUS_PENDING,
        ]);

        try {
            $invoice = $this->invoiceService->createInvoice($storeId, [
                'amount' => (string) $pack['sats'],
                'currency' => 'SATS',
                'metadata' => [
                    'purpose' => 'company_slot_pack',
                    'packSlots' => (string) $pack['slots'],
                    'userId' => (string) $user->id,
                    'purchaseId' => $purchase->id,
                ],
                'checkout' => [
                    'redirectURL' => rtrim(config('app.url'), '/').'/invoicing?company_slots=paid',
                    'expirationMinutes' => 60,
                ],
            ]);
        } catch (\Throwable $e) {
            // No invoice exists, so the pending row can never be fulfilled - drop it.
            $purchase->delete();

            throw $e;
        }

        $invoiceId = $invoice['id'] ?? null;
        $checkoutLink = $invoice['checkoutLink'] ?? null;
        if (! $invoiceId || ! $checkoutLink) {
            $purchase->delete();

            throw ValidationException::withMessages([
                'billing' => ['Could not create payment invoice.'],
            ]);
        }

        $purchase->update(['btcpay_invoice_id' => $invoiceId]);

        AuditLog::log('company_slot.purchase_started', 'company_slot_purchase', $purchase->id, [
            'slots' => $pack['slots'],
            'price_sats' => $pack['sats'],
            'btcpay_invoice_id' => $invoiceId,
        ], $user->id);

        return [
            'checkoutLink' => $checkoutLink,
            'purchaseId' => $purchase->id,
            'slots' => $pack['slots'],
            'price_sats' => $pack['sats'],
        ];
    }

    public function fulfillPaidInvoice(string $btcpayInvoiceId, ?string $userId = null, ?string $purchaseId = null): bool
    {
        $purchase = CompanySlotPurchase::query()
            ->where('btcpay_invoice_id', $btcpayInvoiceId)
            ->first();

        if (! $purchase && $purchaseId) {
            $purchase = CompanySlotPurchase::query()->find($purchaseId);
        }

        if (! $purchase || $purchase->status === CompanySlotPurchase::STATUS_PAID) {
            return false;
        }

        if ($userId && (string) $purchase->user_id !== (string) $userId) {
            return false;
        }

        $purchase->update([
            'status' => CompanySlotPurchase::STATUS_PAID,
            'paid_at' => now(),
        ]);

        // The limits endpoint caches for 60s - purge so the new slot shows up.
        Cache::forget('user_limits_'.$purchase->user_id);

        AuditLog::log('company_slot.purchase_fulfilled', 'company_slot_purchase', $purchase->id, [
            'slots' => $purchase->slots,
            'btcpay_invoice_id' => $btcpayInvoiceId,
        ], (int) $purchase->user_id);

        return true;
    }

    public function paidSlotCount(User $user): int
    {
        return (int) CompanySlotPurchase::query()
            ->where('user_id', $user->id)
            ->where('status', CompanySlotPurchase::STATUS_PAID)
            ->sum('slots');
    }
}
