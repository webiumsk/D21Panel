<?php

namespace App\Services;

use App\Mail\StoreInvoiceEmail;
use App\Models\Store;
use App\Models\StoreEmailRule;
use App\Models\StoreEmailRuleDispatch;
use App\Models\WebhookEvent;
use App\Services\BtcPay\InvoiceService;
use App\Support\BtcPay\BtcPayWebhookEventType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use JsonPath\JsonObject;

class StoreEmailRuleDispatcher
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

    /**
     * Send matching store email rules for an invoice webhook (Satflux-local rules, not BTCPay email_rules).
     */
    public function dispatchForWebhook(WebhookEvent $webhookEvent, Store $store): void
    {
        $payload = $webhookEvent->payload ?? [];
        $eventType = $this->normalizeEventType((string) $webhookEvent->event_type);
        $allowed = config('invoice_email_triggers', []);

        if (! in_array($eventType, $allowed, true)) {
            return;
        }

        try {
            $userApiKey = $store->user->getBtcPayApiKeyOrFail();
        } catch (\Throwable) {
            Log::warning('Store email rules: BTCPay API key not configured for store owner', [
                'store_id' => $store->id,
            ]);

            return;
        }

        $invoiceId = $this->extractInvoiceId($payload);
        if (! $invoiceId) {
            Log::debug('Store email rules: no invoice id in webhook payload', [
                'store_id' => $store->id,
                'event_type' => $eventType,
            ]);

            return;
        }

        $rules = StoreEmailRule::query()
            ->where('store_id', $store->id)
            ->where('trigger', $eventType)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $this->invoiceService->forgetInvoiceCache($store->btcpay_store_id, $invoiceId, $userApiKey);

        try {
            $invoice = $this->invoiceService->getInvoice(
                $store->btcpay_store_id,
                $invoiceId,
                $userApiKey
            );
        } catch (\Throwable $e) {
            Log::warning('Store email rules: failed to fetch invoice from BTCPay', [
                'store_id' => $store->id,
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $context = $this->buildTemplateContext($store, $invoice);
        $flat = $this->flattenForPlaceholders($context, '');

        $dispatchKey = $this->dispatchKeyFromPayload($payload, $webhookEvent->id);

        foreach ($rules as $rule) {
            if (StoreEmailRuleDispatch::query()
                ->where('store_email_rule_id', $rule->id)
                ->where('dispatch_key', $dispatchKey)
                ->exists()) {
                continue;
            }

            if (! $this->passesCondition($rule->condition, $context)) {
                continue;
            }

            $buyerEmail = $this->extractBuyerEmailFromInvoice($invoice);

            $to = $this->parseRecipientField($rule->to_addresses, $flat);
            if ($rule->send_to_buyer && $buyerEmail) {
                $to[] = $buyerEmail;
            }
            $to = array_values(array_unique(array_filter($to)));
            if ($to === []) {
                Log::info('Store email rule skipped: no recipients', [
                    'rule_id' => $rule->id,
                    'store_id' => $store->id,
                ]);

                continue;
            }

            $cc = $this->parseRecipientField((string) ($rule->cc_addresses ?? ''), $flat);
            $bcc = $this->parseRecipientField((string) ($rule->bcc_addresses ?? ''), $flat);

            $subject = $this->replacePlaceholders($rule->subject, $flat);
            $body = $this->replacePlaceholders($rule->body, $flat);

            try {
                StoreEmailRuleDispatch::create([
                    'store_email_rule_id' => $rule->id,
                    'webhook_event_id' => $webhookEvent->id,
                    'dispatch_key' => $dispatchKey,
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'unique')) {
                    continue;
                }
                throw $e;
            }

            try {
                Mail::send(new StoreInvoiceEmail($subject, $body, $to, $cc, $bcc));
            } catch (\Throwable $e) {
                Log::error('Store email rule send failed', [
                    'rule_id' => $rule->id,
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function normalizeEventType(string $eventType): string
    {
        return BtcPayWebhookEventType::normalize($eventType);
    }

    protected function extractInvoiceId(array $payload): ?string
    {
        $candidates = [
            $payload['invoiceId'] ?? null,
            $payload['invoice_id'] ?? null,
            $payload['metadata']['invoiceId'] ?? null,
        ];

        $nested = $payload['invoice'] ?? $payload['invoiceData'] ?? null;
        if (is_array($nested)) {
            $candidates[] = $nested['id'] ?? $nested['invoiceId'] ?? null;
        }

        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c;
            }
        }

        return null;
    }

    protected function dispatchKeyFromPayload(array $payload, int $webhookEventId): string
    {
        $deliveryId = $payload['deliveryId'] ?? $payload['delivery_id'] ?? null;
        if (is_string($deliveryId) && $deliveryId !== '') {
            return $deliveryId;
        }

        return 'we:'.$webhookEventId;
    }

    protected function buildTemplateContext(Store $store, array $invoice): array
    {
        $meta = is_array($store->metadata) ? $store->metadata : [];

        return [
            'Invoice' => $this->invoiceToTemplateArray($invoice),
            'Store' => [
                'Id' => $store->btcpay_store_id,
                'Name' => $store->name,
                'WebsiteUrl' => (string) ($meta['website'] ?? $meta['website_url'] ?? ''),
                'SupportUrl' => (string) ($meta['support_url'] ?? ''),
            ],
        ];
    }

    protected function invoiceToTemplateArray(array $invoice): array
    {
        return [
            'Id' => $invoice['id'] ?? '',
            'OrderId' => $invoice['metadata']['orderId'] ?? $invoice['orderId'] ?? $invoice['metadata']['order_id'] ?? '',
            'Status' => $invoice['status'] ?? '',
            'Amount' => isset($invoice['amount']) ? (string) $invoice['amount'] : '',
            'Currency' => $invoice['currency'] ?? '',
            'CheckoutLink' => $invoice['checkoutLink'] ?? '',
            'Link' => $invoice['checkoutLink'] ?? '',
            'Metadata' => $invoice['metadata'] ?? [],
        ];
    }

    protected function passesCondition(?string $condition, array $context): bool
    {
        $condition = trim((string) $condition);
        if ($condition === '') {
            return true;
        }

        if (! class_exists(JsonObject::class)) {
            Log::warning('Store email rule condition cannot run: install galbar/jsonpath (composer require galbar/jsonpath)');

            return false;
        }

        try {
            $json = json_encode($context, JSON_THROW_ON_ERROR);
            $j = new JsonObject($json);
            $result = $j->get($condition);

            return $result !== [] && $result !== null;
        } catch (\Throwable $e) {
            Log::warning('Store email rule condition evaluation failed', [
                'condition' => $condition,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function flattenForPlaceholders(array $data, string $prefix): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                if ($this->isAssocArray($value)) {
                    $out += $this->flattenForPlaceholders($value, $path);
                } else {
                    $out[$path] = json_encode($value, JSON_UNESCAPED_SLASHES);
                }
            } else {
                $out[$path] = $value === null || is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return $out;
    }

    protected function isAssocArray(array $arr): bool
    {
        return $arr !== [] && array_keys($arr) !== range(0, count($arr) - 1);
    }

    protected function replacePlaceholders(string $template, array $flat): string
    {
        return (string) preg_replace_callback('/\{([^}]+)\}/', function (array $m) use ($flat) {
            $key = trim($m[1]);

            return $flat[$key] ?? $m[0];
        }, $template);
    }

    /**
     * @return list<string>
     */
    protected function parseRecipientField(string $field, array $flat): array
    {
        $rendered = $this->replacePlaceholders($field, $flat);
        $parts = array_map('trim', explode(',', $rendered));

        return array_values(array_filter($parts, fn (string $p) => $p !== '' && filter_var($this->extractEmailAddress($p), FILTER_VALIDATE_EMAIL)));
    }

    /**
     * Rough extract email from "Name <a@b.com>" or plain email.
     */
    protected function extractEmailAddress(string $part): string
    {
        if (preg_match('/<([^>]+)>/', $part, $m)) {
            return trim($m[1]);
        }

        return trim($part);
    }

    protected function extractBuyerEmailFromInvoice(array $invoice): ?string
    {
        $meta = $invoice['metadata'] ?? [];
        if (! is_array($meta)) {
            $meta = [];
        }

        $candidates = [
            $meta['buyerEmail'] ?? null,
            $meta['customerEmail'] ?? null,
            $meta['email'] ?? null,
            $invoice['buyerEmail'] ?? null,
            $invoice['customerEmail'] ?? null,
        ];

        foreach ($candidates as $c) {
            if (is_string($c) && filter_var($c, FILTER_VALIDATE_EMAIL)) {
                return $c;
            }
        }

        return null;
    }
}
