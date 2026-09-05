<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Models\UserMessage;
use App\Services\WalletSecurity\WalletSecurityNotifier;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * List notifications from BTCPay for the authenticated user.
     */
    /** Prefix that tells a Satflux-local message id apart from a BTCPay notification id. */
    public const LOCAL_ID_PREFIX = 'sf_';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $apiKey = $user->btcpay_api_key;
        $page = max((int) $request->get('page', 1), 1);
        // Satflux-local security messages ride on top of page 1 of the BTCPay feed.
        $local = $page === 1 ? $this->localMessages($user) : [];

        if (! $apiKey) {
            return response()->json([
                'data' => $local,
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 20,
                    'total' => count($local),
                ],
                'available' => $local !== [],
            ]);
        }

        try {
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
            $skip = ($page - 1) * $perPage;

            $result = $this->notificationService->listNotifications($apiKey, [
                'skip' => $skip,
                'take' => $perPage,
            ]);

            $raw = $result['data'] ?? [];
            $items = array_map([$this, 'mapBtcPayNotification'], is_array($raw) ? $raw : []);
            $items = array_merge($local, $items);

            // BTCPay doesn't return total; we use a heuristic for pagination
            $hasMore = count($raw) >= $perPage;
            $total = $hasMore ? $skip + count($raw) + 1 : $skip + count($raw);

            return response()->json([
                'data' => $items,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $hasMore ? $page + 1 : $page,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
                'available' => true,
            ]);
        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 403) {
                return response()->json([
                    'data' => $local,
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 20,
                        'total' => count($local),
                    ],
                    'available' => $local !== [],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Get unread notification count from BTCPay.
     */
    public function count(Request $request): JsonResponse
    {
        $user = $request->user();
        $apiKey = $user->btcpay_api_key;
        $securityUnread = UserMessage::query()
            ->where('user_id', $user->id)
            ->where('type', WalletSecurityNotifier::TYPE)
            ->whereNull('read_at')
            ->count();

        if (! $apiKey) {
            return response()->json([
                'data' => ['unread' => $securityUnread, 'security_unread' => $securityUnread],
                'available' => $securityUnread > 0,
            ]);
        }

        try {
            $unread = $this->notificationService->getUnreadCount($apiKey);

            return response()->json([
                'data' => ['unread' => $unread + $securityUnread, 'security_unread' => $securityUnread],
                'available' => true,
            ]);
        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 403) {
                return response()->json([
                    'data' => ['unread' => $securityUnread, 'security_unread' => $securityUnread],
                    'available' => $securityUnread > 0,
                ]);
            }
            throw $e;
        }
    }

    /**
     * Mark a BTCPay notification as seen.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (str_starts_with($id, self::LOCAL_ID_PREFIX)) {
            $message = UserMessage::query()
                ->where('user_id', $user->id)
                ->whereKey((int) substr($id, strlen(self::LOCAL_ID_PREFIX)))
                ->firstOrFail();
            $message->markAsRead();

            return response()->json(['data' => $this->mapLocalMessage($message)]);
        }
        $apiKey = $user->btcpay_api_key;

        if (! $apiKey) {
            return response()->json(['message' => 'No BTCPay API key'], 400);
        }

        try {
            $updated = $this->notificationService->markAsSeen($apiKey, $id, true);

            return response()->json([
                'data' => $this->mapBtcPayNotification($updated),
            ]);
        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 404) {
                abort(404);
            }
            throw $e;
        }
    }

    /**
     * Mark all BTCPay notifications as seen (batch).
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        UserMessage::query()->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
        $apiKey = $user->btcpay_api_key;

        if (! $apiKey) {
            return response()->json(['data' => ['success' => true]]);
        }

        try {
            $result = $this->notificationService->listNotifications($apiKey, [
                'take' => 100,
                'skip' => 0,
                'seen' => false,
            ]);

            $unread = $result['data'] ?? [];
            foreach (is_array($unread) ? $unread : [] as $n) {
                $nid = $n['id'] ?? null;
                if ($nid) {
                    try {
                        $this->notificationService->markAsSeen($apiKey, $nid, true);
                    } catch (\Throwable $e) {
                        // Continue with others
                    }
                }
            }

            return response()->json(['data' => ['success' => true]]);
        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 403) {
                return response()->json(['data' => ['success' => true]]);
            }
            throw $e;
        }
    }

    /**
     * Satflux-local messages (security alerts): unread ones always, read ones
     * from the last 30 days, newest first, capped so the page stays readable.
     *
     * @return list<array<string, mixed>>
     */
    protected function localMessages(User $user): array
    {
        $rows = UserMessage::query()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('read_at')->orWhere('created_at', '>=', now()->subDays(30));
            })
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->latest('created_at')
            ->limit(20)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapLocalMessage($row);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function mapLocalMessage(UserMessage $message): array
    {
        return [
            'id' => self::LOCAL_ID_PREFIX.$message->id,
            'type' => $message->type,
            'title' => $message->title,
            'body' => $message->body,
            'link' => $message->link,
            'link_text' => $message->link_text,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * Map BTCPay notification to frontend format.
     *
     * @param  array{id?: string, identifier?: string, type?: string, body?: string, storeId?: string, link?: string, createdTime?: int, seen?: bool}  $n
     */
    protected function mapBtcPayNotification(array $n): array
    {
        $id = $n['id'] ?? '';
        $body = $n['body'] ?? '';
        $type = $n['type'] ?? 'info';
        $createdTime = $n['createdTime'] ?? 0;
        $seen = $n['seen'] ?? false;

        $title = $this->deriveTitle($type, $body);

        $link = $this->rewriteNotificationLinkToSatflux($n['link'] ?? null, $n['storeId'] ?? null);

        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'link_text' => null,
            'read_at' => $seen ? gmdate('Y-m-d\TH:i:s\Z', $createdTime) : null,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $createdTime),
        ];
    }

    /**
     * Rewrite BTCPay notification links to Satflux URLs.
     * Users never log in to BTCPay - links must stay within the panel.
     *
     * @param  string|null  $link  Original link from BTCPay (e.g. /stores/xxx/invoices/yyy or relative path)
     * @param  string|null  $storeId  BTCPay store ID from notification
     */
    protected function rewriteNotificationLinkToSatflux(?string $link, ?string $storeId): ?string
    {
        if (! $link) {
            return null;
        }
        $link = trim($link);
        if ($link === '') {
            return null;
        }
        // Already absolute - if it points to BTCPay, we drop it; if external, leave as-is would be rare
        if (str_starts_with($link, 'http')) {
            $btcpayBase = rtrim(config('services.btcpay.base_url', ''), '/');
            if ($btcpayBase !== '' && str_starts_with($link, $btcpayBase)) {
                return null; // BTCPay URL - do not expose
            }

            return $link; // External URL (e.g. payout explorer) - keep
        }
        $path = str_starts_with($link, '/') ? $link : '/'.$link;
        $panelUrl = rtrim(config('app.url', ''), '/');
        if ($panelUrl === '') {
            return null;
        }
        // /stores/{btcpay_store_id}/invoices/{invoice_id}
        if (preg_match('#^/stores/([^/]+)/invoices/([^/]+)(?:/.*)?$#', $path, $m)) {
            $btcpayStoreId = $m[1];
            $invoiceId = $m[2];
            $store = Store::where('btcpay_store_id', $btcpayStoreId)->first();
            if ($store) {
                return "{$panelUrl}/stores/{$store->id}/invoices/{$invoiceId}";
            }
        }
        // /i/{invoice_id} - short invoice link; we'd need storeId from notification
        if (preg_match('#^/i/([^/]+)$#', $path, $m) && $storeId) {
            $invoiceId = $m[1];
            $store = Store::where('btcpay_store_id', $storeId)->first();
            if ($store) {
                return "{$panelUrl}/stores/{$store->id}/invoices/{$invoiceId}";
            }
        }

        return null; // Unmapped BTCPay path - no link
    }

    protected function deriveTitle(string $type, string $body): string
    {
        $typeLabels = [
            'InvoiceCreated' => 'Invoice created',
            'InvoiceReceivedPayment' => 'Payment received',
            'InvoiceProcessing' => 'Invoice processing',
            'InvoiceExpired' => 'Invoice expired',
            'InvoiceSettled' => 'Invoice settled',
            'InvoiceInvalid' => 'Invoice invalid',
            'PayoutComplete' => 'Payout complete',
            'PayoutFailed' => 'Payout failed',
            'NewVersion' => 'New version available',
        ];

        if (isset($typeLabels[$type])) {
            return $typeLabels[$type];
        }

        $firstLine = trim(explode("\n", $body)[0] ?? '');
        if (strlen($firstLine) > 80) {
            return substr($firstLine, 0, 77).'...';
        }

        return $firstLine ?: 'Notification';
    }
}
