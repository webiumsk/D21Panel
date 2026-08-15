<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\IntegrationDocumentInbox;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\User;
use App\Services\Integrations\IntegrationAutoIssueService;
use App\Services\Invoicing\BusinessDocumentEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Delivers an auto-issued WooCommerce invoice to the customer (P3 headless
 * invoicing). Queued so SMTP latency or a transient failure never blocks the
 * order flow - the number is already allocated and stamped; a failed email
 * only stamps email_error on the inbox payload (visible on import).
 */
class SendWooAutoInvoiceEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300];

    public function __construct(
        public string $inboxEntryId,
    ) {}

    public function handle(
        IntegrationAutoIssueService $autoIssueService,
        BusinessDocumentEmailService $emailService,
    ): void {
        // Atomic claim: a timed-out attempt may still be sending when its
        // retry starts - without the lock both would pass the emailed_at
        // check and the customer would get the invoice twice. The lock is
        // atomic on the production cache (redis); emailed_at below stays as
        // the persistent cross-attempt dedupe once a send succeeded.
        $lock = Cache::lock('woo-auto-invoice-email:'.$this->inboxEntryId, 300);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->sendClaimed($autoIssueService, $emailService);
        } finally {
            $lock->release();
        }
    }

    protected function sendClaimed(
        IntegrationAutoIssueService $autoIssueService,
        BusinessDocumentEmailService $emailService,
    ): void {
        $entry = IntegrationDocumentInbox::query()->find($this->inboxEntryId);
        if (! $entry) {
            // Imported (deleted) before the email went out - the merchant's
            // browser now owns the document; nothing sensible to send from.
            Log::info('Woo auto-invoice email skipped: inbox entry gone', [
                'inbox_id' => $this->inboxEntryId,
            ]);

            return;
        }

        $payload = $entry->payload_json;
        if (! empty($payload['emailed_at'])) {
            return;
        }

        $buyerEmail = trim((string) ($payload['buyer']['email'] ?? ''));
        if ($buyerEmail === '' || empty($payload['number'])) {
            return;
        }

        /** @var StoreIntegration|null $integration */
        $integration = StoreIntegration::query()
            ->with(['store', 'company'])
            ->find($entry->store_integration_id);
        if (! $integration) {
            return;
        }

        $company = $integration->company;
        if (! $company instanceof Company) {
            $store = $integration->store;
            $company = $store instanceof Store ? $store->company : null;
        }
        if (! $company instanceof Company) {
            return;
        }

        $context = $autoIssueService->resolveProfileContext($company);
        if (! $context) {
            return;
        }
        // Render + send from the profile's company - the same context the
        // number was allocated on.
        $profileCompany = $context['company'];
        $profile = $context['profile'];

        $snapshotCompany = $autoIssueService->buildCompany($profileCompany, $profile);
        $document = $autoIssueService->buildDocument($profileCompany, $entry, $profile);

        $sender = $company->user;

        $emailService->sendEphemeral(
            company: $snapshotCompany,
            document: $document,
            sender: $sender instanceof User ? $sender : null,
            to: [$buyerEmail],
        );

        $autoIssueService->stampEmailResult($entry, true);

        AuditLog::log('integration_inbox.auto_invoice_emailed', 'company', $profileCompany->id, [
            'inbox_id' => $entry->id,
            'number' => $payload['number'],
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $entry = IntegrationDocumentInbox::query()->find($this->inboxEntryId);
        if ($entry) {
            app(IntegrationAutoIssueService::class)->stampEmailResult(
                $entry,
                false,
                $exception ? get_class($exception) : 'send_failed',
            );
        }
    }
}
