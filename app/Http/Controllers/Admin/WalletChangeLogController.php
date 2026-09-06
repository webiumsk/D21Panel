<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\WalletSecurity\PayeeAttestationService;
use App\Services\WalletSecurity\WalletConfigIntegrityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin report of everything that touched a store's receiving wallet: the
 * audit trail (created / configured / connected / revealed / change grants /
 * drift) plus the currently drifting connections.
 */
class WalletChangeLogController extends Controller
{
    public const ACTIONS = [
        'wallet_connection.created',
        'wallet_connection.configured',
        'wallet_connection.marked_connected',
        'wallet_connection.deleted',
        'wallet_connection.revealed',
        'wallet_connection.test_connection',
        'wallet_connection.change_requested',
        'wallet_connection.change_granted',
        'wallet_connection.config_baselined',
        'wallet_connection.drift_detected',
        'wallet_connection.drift_resolved',
        'wallet_connection.payee_learned',
        'wallet_connection.payee_mismatch',
        'wallet_connection.payee_accepted',
        'store.cashu_fallback_configured',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'in:'.implode(',', self::ACTIONS)],
            'store_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $query = AuditLog::query()
            ->whereIn('action', self::ACTIONS)
            ->with('user:id,email,name');

        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (! empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }
        if (! empty($validated['store_id'])) {
            $storeId = $validated['store_id'];
            $query->where(function ($q) use ($storeId) {
                $q->where('metadata->store_id', $storeId)
                    ->orWhere(function ($q2) use ($storeId) {
                        $q2->where('target_type', 'store')->where('target_id', $storeId);
                    });
            });
        }
        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to'].' 23:59:59');
        }
        if (! empty($validated['q'])) {
            $term = '%'.strtolower($validated['q']).'%';
            $storeIds = Store::query()->whereRaw('LOWER(name) LIKE ?', [$term])->pluck('id')->all();
            $userIds = User::query()->whereRaw('LOWER(email) LIKE ?', [$term])->pluck('id')->all();
            $storeIds = $storeIds ?: ['00000000-0000-0000-0000-000000000000'];
            $query->where(function ($q) use ($storeIds, $userIds) {
                $q->whereIn('user_id', $userIds ?: [-1])
                    ->orWhereIn('metadata->store_id', $storeIds)
                    ->orWhere(function ($q2) use ($storeIds) {
                        $q2->where('target_type', 'store')->whereIn('target_id', $storeIds);
                    });
            });
        }

        $page = $query->latest('created_at')->paginate((int) ($validated['per_page'] ?? 50));

        /** @var list<AuditLog> $logs */
        $logs = $page->items();
        $storeIds = [];
        foreach ($logs as $log) {
            if ($id = $this->storeIdOf($log)) {
                $storeIds[$id] = true;
            }
        }
        /** @var array<string, Store> $stores */
        $stores = Store::query()->whereIn('id', array_keys($storeIds))->get(['id', 'name', 'user_id'])->keyBy('id')->all();

        $items = [];
        foreach ($logs as $log) {
            $storeId = $this->storeIdOf($log);
            $store = $storeId !== null ? ($stores[$storeId] ?? null) : null;
            $actor = $log->user;

            $items[] = [
                'id' => $log->id,
                'action' => $log->action,
                'created_at' => $log->created_at?->toIso8601String(),
                'user' => $actor instanceof User ? ['id' => $actor->id, 'email' => $actor->email] : null,
                'store' => $store instanceof Store
                    ? ['id' => $store->id, 'name' => $store->name]
                    : ($storeId !== null ? ['id' => $storeId, 'name' => null] : null),
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'metadata' => $this->publicMetadata($log->metadata ?? []),
            ];
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'actions' => self::ACTIONS,
        ]);
    }

    /** Connections whose BTCPay config currently differs from the Satflux baseline. */
    public function drifts(): JsonResponse
    {
        $rows = WalletConnection::query()
            ->where(function ($q) {
                $q->whereNotNull('drift_detected_at')->orWhereNotNull('payee_mismatch_at');
            })
            ->with(['store:id,name,user_id', 'store.user:id,email'])
            ->orderByRaw('COALESCE(drift_detected_at, payee_mismatch_at) DESC')
            ->get();

        $data = [];
        foreach ($rows as $c) {
            $store = $c->store;
            $owner = $store instanceof Store ? $store->user : null;
            $data[] = [
                'id' => $c->id,
                'store' => $store instanceof Store ? ['id' => $store->id, 'name' => $store->name] : null,
                'owner_email' => $owner instanceof User ? $owner->email : null,
                'type' => $c->type,
                'masked_secret' => $c->masked_secret,
                'drift_detected_at' => $c->drift_detected_at?->toIso8601String(),
                'config_verified_at' => $c->config_verified_at?->toIso8601String(),
                'drift_details' => $c->drift_details,
                'payee_pubkeys' => $c->payee_pubkeys,
                'payee_learn_source' => $c->payee_learn_source,
                'payee_mismatch_at' => $c->payee_mismatch_at?->toIso8601String(),
                'payee_mismatch_details' => $c->payee_mismatch_details,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** Re-check one connection now. */
    public function verify(WalletConnection $connection, WalletConfigIntegrityService $integrity): JsonResponse
    {
        return response()->json(['data' => $integrity->verify($connection)]);
    }

    /**
     * Accept the live BTCPay config as the new baseline (after an admin has
     * investigated and confirmed the change is legitimate).
     */
    public function rebaseline(Request $request, WalletConnection $connection, WalletConfigIntegrityService $integrity): JsonResponse
    {
        $ok = $integrity->baseline($connection, $request->user(), 'admin_accepted');

        return response()->json(['data' => ['baselined' => $ok]], $ok ? 200 : 502);
    }

    /** Admin confirms the node that signed the mismatching invoice (e.g. the merchant changed provider). */
    public function acceptPayee(Request $request, WalletConnection $connection, PayeeAttestationService $payees): JsonResponse
    {
        $validated = $request->validate(['pubkey' => ['required', 'string', 'regex:/^0[23][0-9a-fA-F]{64}$/']]);
        $payees->accept($connection, $validated['pubkey'], $request->user());

        return response()->json(['data' => ['payee_pubkeys' => $connection->fresh()?->payee_pubkeys]]);
    }

    /** Relearn the payee allow-list from a fresh canary invoice. */
    public function learnPayee(Request $request, WalletConnection $connection, PayeeAttestationService $payees): JsonResponse
    {
        $ok = $payees->learn($connection, $request->user(), 'admin_relearn');

        return response()->json(['data' => ['learned' => $ok, 'payee_pubkeys' => $connection->fresh()?->payee_pubkeys]], $ok ? 200 : 502);
    }

    /** Store the row is about: metadata.store_id, or the target itself for store-targeted rows. */
    private function storeIdOf(AuditLog $log): ?string
    {
        $meta = $log->metadata ?? [];
        if (! empty($meta['store_id'])) {
            return (string) $meta['store_id'];
        }

        return $log->target_type === 'store' && $log->target_id ? (string) $log->target_id : null;
    }

    /** Strip anything secret-like from audit metadata before it leaves the API. */
    private function publicMetadata(array $metadata): array
    {
        unset($metadata['secret'], $metadata['connection_string'], $metadata['password']);

        return $metadata;
    }
}
