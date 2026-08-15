<?php

namespace App\Services\Integrations;

use App\Jobs\SendWooAutoInvoiceEmail;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyAutoIssueProfile;
use App\Models\IntegrationDocumentInbox;
use App\Services\Invoicing\DocumentSequenceService;
use App\Services\Invoicing\EphemeralDocumentFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Headless auto-issue for paid WooCommerce orders (local-first inbox mode).
 *
 * When a PAID order lands in the integration inbox and the company opted in
 * (CompanyAutoIssueProfile synced from the client), the server allocates the
 * invoice number through the shared reservation allocator, stamps the inbox
 * payload and queues the customer email. The inbox entry stays Pending so the
 * merchant's browser later imports it WITH the pre-assigned number - the
 * local Evolu dataset remains the document of record and converges without
 * duplicates (client path: payload.number → applyReservedNumber).
 */
class IntegrationAutoIssueService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected EphemeralDocumentFactory $documentFactory,
    ) {}

    /** @var array<string, CompanyAutoIssueProfile|null> */
    protected array $profileCache = [];

    /**
     * Memoized per request: maybeAutoIssue() and the response diagnostics
     * (skipReason) both need the profile of the same company.
     */
    public function profileFor(Company $company): ?CompanyAutoIssueProfile
    {
        if (! array_key_exists($company->id, $this->profileCache)) {
            $this->profileCache[$company->id] = CompanyAutoIssueProfile::query()
                ->where('company_id', $company->id)
                ->first();
        }

        return $this->profileCache[$company->id];
    }

    /**
     * Auto-issue context: which company's profile AND number series to use.
     *
     * The integration may be linked to a DIFFERENT server company row than
     * the bridge company the client synced the profile to (the client
     * resolves bridge companies by legal identity and creates them on
     * demand; legacy integrations point at rows from the connect era -
     * production 2026-07-14: auto-issue silently skipped while the profile
     * AND the browser allocator both lived on the bridge row). Fallback:
     * when the integration company has no profile and its OWNER has exactly
     * ONE, use that one - the number then comes from the very series the
     * merchant's browser allocates on, keeping the sequence consistent.
     * With several profiles nothing is guessed (ambiguous_profile): the
     * store must be linked to the right company.
     *
     * @return array{company: Company, profile: CompanyAutoIssueProfile}|null
     */
    public function resolveProfileContext(Company $company): ?array
    {
        $direct = $this->profileFor($company);
        if ($direct) {
            return ['company' => $company, 'profile' => $direct];
        }

        $userProfiles = $this->ownerProfiles($company);
        if ($userProfiles->count() !== 1) {
            return null;
        }

        $profile = $userProfiles->first();
        $profileCompany = $profile?->company;
        if (! $profile || ! $profileCompany instanceof Company) {
            return null;
        }

        return ['company' => $profileCompany, 'profile' => $profile];
    }

    public function ownerProfileCount(Company $company): int
    {
        return $this->ownerProfiles($company)->count();
    }

    /** @var array<string, Collection<int, CompanyAutoIssueProfile>> */
    protected array $ownerProfilesCache = [];

    /**
     * All auto-issue profiles of the company's owner, memoized per request -
     * resolveProfileContext() and the skipReason() diagnostics both need it.
     *
     * @return Collection<int, CompanyAutoIssueProfile>
     */
    protected function ownerProfiles(Company $company): Collection
    {
        $userId = (string) $company->user_id;
        if (! array_key_exists($userId, $this->ownerProfilesCache)) {
            $this->ownerProfilesCache[$userId] = CompanyAutoIssueProfile::query()
                ->whereHas('company', fn ($query) => $query->where('user_id', $userId))
                ->with('company')
                ->get();
        }

        return $this->ownerProfilesCache[$userId];
    }

    /** Document types the headless flow issues, with their payment policy. */
    protected const AUTO_ISSUE_TYPES = [
        // A real invoice is only issued once the order is PAID.
        'invoice' => ['requires_paid' => true],
        // A proforma is the payment REQUEST (COD / bank transfer orders) -
        // it is issued precisely while unpaid.
        'proforma' => ['requires_paid' => false],
    ];

    /**
     * Issue + queue delivery for a freshly enqueued inbox entry: paid orders
     * get an invoice, deferred-payment orders a proforma (per-gateway
     * mapping in the WooCommerce plugin decides which type arrives).
     * Idempotent: an already numbered payload is returned unchanged.
     */
    public function maybeAutoIssue(Company $company, IntegrationDocumentInbox $entry): IntegrationDocumentInbox
    {
        $payload = $entry->payload_json;

        if (! empty($payload['number'])) {
            return $entry;
        }
        $documentType = (string) ($payload['type'] ?? 'invoice');
        $policy = self::AUTO_ISSUE_TYPES[$documentType] ?? null;
        if ($policy === null) {
            return $entry;
        }
        if ($policy['requires_paid'] && empty($payload['is_paid'])) {
            return $entry;
        }

        $context = $this->resolveProfileContext($company);
        if (! $context) {
            return $entry;
        }
        // Allocate on the PROFILE's company - the same series the merchant's
        // browser uses - never on a possibly divergent integration link.
        $allocatorCompany = $context['company'];
        $profile = $context['profile'];

        // Shared allocator: reservation is idempotent per issue request and
        // raises the counter floor the local-first client allocator respects,
        // so a later browser-side issue can never collide with this number.
        // The synced local high counter covers invoices issued locally BEFORE
        // the shared allocator existed (post-allocator issues are protected
        // by the reservation floor itself).
        $localHighCounters = $profile->profile_json['local_high_counters'] ?? [];
        $localHighCounter = is_array($localHighCounters) && isset($localHighCounters[$documentType])
            ? (int) $localHighCounters[$documentType]
            : null;

        // Keyed by the WooCommerce ORDER, not the inbox entry: a re-sent
        // order creates a fresh inbox row, and a per-entry key would burn a
        // new number each time (observed in production as sequence gaps).
        // With the order key the re-send is stamped with the SAME number.
        $issueRequestId = $this->issueRequestIdFor($entry, $documentType);

        // Atomic guard (Cache::lock, redis in production): concurrent webhook
        // deliveries for the same order must not both observe "no reservation
        // yet" between findReservation() and reserveNumberForIssue() and
        // double-queue the customer email. Unrelated issue request ids use
        // distinct locks and are unaffected.
        $lock = Cache::lock('woo-auto-issue:'.$allocatorCompany->id.':'.$issueRequestId, 30);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            // The concurrent holder is doing the same work - return its result.
            return $entry->fresh() ?? $entry;
        }

        try {
            $entry = $entry->fresh() ?? $entry;
            $payload = $entry->payload_json;
            if (! empty($payload['number'])) {
                return $entry;
            }

            $alreadyReserved = $this->sequenceService->findReservation($allocatorCompany, $documentType, $issueRequestId) !== null;

            $reservation = $this->sequenceService->reserveNumberForIssue(
                $allocatorCompany,
                $documentType,
                $issueRequestId,
                $localHighCounter,
            );
            $this->sequenceService->confirmReservation(
                $allocatorCompany,
                $documentType,
                $issueRequestId,
                hash('sha256', json_encode($payload) ?: ''),
                // NB: document_number_reservations.confirmed_format_version is varchar(16).
                'woo-auto-v1',
            );

            $payload['number'] = $reservation->number;
            $payload['variable_symbol'] = preg_replace('/\D/', '', $reservation->number) ?: null;
            $payload['issued_at'] = now()->toIso8601String();
            $payload['auto_issued_at'] = now()->toIso8601String();

            $buyerEmail = trim((string) ($payload['buyer']['email'] ?? ''));
            // A pre-existing reservation means an earlier inbox entry for this
            // order was already auto-issued (and its email queued) - stamp the
            // same number again but never email the customer twice.
            $shouldEmail = $profile->auto_email && $buyerEmail !== '' && ! $alreadyReserved;
            if ($shouldEmail) {
                $payload['email_queued_at'] = now()->toIso8601String();
            }

            $entry->payload_json = $payload;
            $entry->save();

            AuditLog::log('integration_inbox.auto_issued', 'company', $allocatorCompany->id, [
                'inbox_id' => $entry->id,
                'number' => $reservation->number,
                'email_queued' => $shouldEmail,
            ]);

            if ($shouldEmail) {
                SendWooAutoInvoiceEmail::dispatch($entry->id);
            }

            return $entry->fresh() ?? $entry;
        } finally {
            $lock->release();
        }
    }

    /**
     * Why maybeAutoIssue() would skip this entry - surfaced to the plugin so
     * a "stayed in inbox" order carries its own explanation (ops asked for
     * this after a silent no-profile miss in production, 2026-07-14).
     * Null when the entry was (or would be) auto-issued.
     */
    public function skipReason(Company $company, IntegrationDocumentInbox $entry): ?string
    {
        $payload = $entry->payload_json;

        if (! empty($payload['number'])) {
            return null;
        }
        $documentType = (string) ($payload['type'] ?? 'invoice');
        $policy = self::AUTO_ISSUE_TYPES[$documentType] ?? null;
        if ($policy === null) {
            return 'document_type';
        }
        if ($policy['requires_paid'] && empty($payload['is_paid'])) {
            return 'not_paid';
        }
        if (! $this->resolveProfileContext($company)) {
            return $this->ownerProfileCount($company) > 1 ? 'ambiguous_profile' : 'no_profile';
        }

        return 'issue_failed';
    }

    /**
     * Reservation idempotency key: per WooCommerce order when known (stable
     * across re-sent inbox entries), per inbox entry otherwise. The invoice
     * keeps the historical un-suffixed key so pre-existing reservations stay
     * idempotent; other types (proforma) are suffixed so ONE order can carry
     * a proforma AND its final invoice on separate sequences.
     */
    public function issueRequestIdFor(IntegrationDocumentInbox $entry, string $documentType = 'invoice'): string
    {
        // Short suffixes: issue_request_id is varchar(64) and the base key
        // already carries a 36-char integration uuid + the order id.
        $suffix = match ($documentType) {
            'invoice' => '',
            'proforma' => ':pf',
            default => ':'.substr(hash('crc32b', $documentType), 0, 4),
        };

        $orderId = (int) ($entry->woocommerce_order_id ?? 0);
        if ($orderId > 0) {
            return 'woo-order:'.$entry->store_integration_id.':'.$orderId.$suffix;
        }

        return 'woo-inbox:'.$entry->evolu_document_id.$suffix;
    }

    /**
     * In-memory document built from a stamped inbox payload + synced profile -
     * the same factory the ephemeral render/email endpoints use.
     */
    public function buildDocument(Company $company, IntegrationDocumentInbox $entry, CompanyAutoIssueProfile $profile): BusinessDocument
    {
        $snapshotCompany = $this->buildCompany($company, $profile);
        $payload = $entry->payload_json;

        return $this->documentFactory->document($snapshotCompany, [
            'contact' => $this->buyerToContactSnapshot(is_array($payload['buyer'] ?? null) ? $payload['buyer'] : []),
            'document' => [
                'type' => (string) ($payload['type'] ?? 'invoice'),
                'status' => 'issued',
                'number' => $payload['number'] ?? null,
                'variable_symbol' => $payload['variable_symbol'] ?? null,
                'issue_date' => $payload['issue_date'] ?? now()->toDateString(),
                'delivery_date' => $payload['delivery_date'] ?? null,
                'due_date' => $payload['due_date'] ?? null,
                'currency' => (string) ($payload['currency'] ?? $company->default_currency),
                'note_above_lines' => $payload['note_above_lines'] ?? null,
                'internal_note' => $payload['internal_note'] ?? null,
                'pdf_locale' => $payload['pdf_locale'] ?? null,
                'payment_btc_enabled' => (bool) ($payload['payment_btc_enabled'] ?? false),
                'payment_bank_enabled' => (bool) ($payload['payment_bank_enabled'] ?? true),
                'discount_percent' => (float) ($payload['discount_percent'] ?? 0),
                // A proforma is a payment request - only PAID payloads carry
                // the settled amount into the render.
                'amount_paid' => ! empty($payload['is_paid']) ? (float) ($payload['order_total'] ?? 0) : 0.0,
            ],
            'lines' => is_array($payload['lines'] ?? null) ? $payload['lines'] : [],
            'store_id' => $payload['store_id'] ?? null,
            'evolu_document_id' => $entry->evolu_document_id,
        ]);
    }

    public function buildCompany(Company $company, CompanyAutoIssueProfile $profile): Company
    {
        $companyPayload = is_array($profile->profile_json['company'] ?? null)
            ? $profile->profile_json['company']
            : [];

        return $this->documentFactory->snapshotCompany($company, $companyPayload);
    }

    /**
     * WooCommerce buyer keys → BuyerSnapshot/CompanyContact attribute names.
     *
     * @param  array<string, mixed>  $buyer
     * @return array<string, mixed>|null
     */
    public function buyerToContactSnapshot(array $buyer): ?array
    {
        $name = trim((string) ($buyer['company'] ?? '')) ?: trim((string) ($buyer['name'] ?? ''));
        if ($name === '' && trim((string) ($buyer['email'] ?? '')) === '') {
            return null;
        }

        return [
            'name' => $name !== '' ? $name : (string) $buyer['email'],
            'email' => $buyer['email'] ?? null,
            'registration_number' => $buyer['ico'] ?? null,
            'tax_id' => $buyer['dic'] ?? null,
            'vat_id' => $buyer['ic_dph'] ?? null,
            'street' => $buyer['street'] ?? null,
            'city' => $buyer['city'] ?? null,
            'postal_code' => $buyer['zip'] ?? null,
            'country' => $buyer['country'] ?? null,
        ];
    }

    /** Stamp delivery evidence; tolerate a concurrently imported (deleted) entry. */
    public function stampEmailResult(IntegrationDocumentInbox $entry, bool $sent, ?string $error = null): void
    {
        try {
            $payload = $entry->payload_json;
            if ($sent) {
                $payload['emailed_at'] = now()->toIso8601String();
                unset($payload['email_error']);
            } else {
                $payload['email_error'] = $error ?? 'send_failed';
            }
            $entry->payload_json = $payload;
            $entry->save();
        } catch (\Throwable $e) {
            Log::warning('Woo auto-issue: could not stamp email result', [
                'inbox_id' => $entry->id,
                'exception' => $e,
            ]);
        }
    }
}
