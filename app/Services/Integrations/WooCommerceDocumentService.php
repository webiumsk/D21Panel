<?php

namespace App\Services\Integrations;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\IntegrationDocumentInbox;
use App\Models\StoreIntegration;
use App\Services\Invoicing\BusinessDocumentIssueService;
use App\Services\Invoicing\BusinessDocumentPdfService;
use App\Services\Invoicing\CompanyPdfFilenameBuilder;
use App\Services\Invoicing\DocumentTotalsCalculator;
use App\Services\SubscriptionEntitlementService;
use App\Support\Invoicing\CompanyAppSettings;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class WooCommerceDocumentService
{
    public function __construct(
        protected DocumentTotalsCalculator $totalsCalculator,
        protected BusinessDocumentIssueService $issueService,
        protected SubscriptionEntitlementService $subscriptionService,
        protected IntegrationDocumentInboxService $inboxService,
        protected IntegrationAutoIssueService $autoIssueService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function connectionInfo(StoreIntegration $integration): array
    {
        $store = $integration->store;
        $user = $store->user;
        $company = $integration->company ?? $store->company;

        $inboxMode = $company && $this->shouldUseInbox($company);

        return [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'btcpay_store_id' => $store->btcpay_store_id,
            ],
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->legal_name,
            ] : null,
            'invoicing_enabled' => $company && $this->subscriptionService->canUseBusinessInvoicing($user),
            // Headless auto-issue is opt-in (profile synced from the client);
            // the plugin uses this flag to tell the merchant it is OFF.
            'auto_issue_enabled' => $company !== null
                && $this->autoIssueService->resolveProfileContext($company) !== null,
            'inbox_mode' => $inboxMode,
            'local_first' => (bool) config('invoicing.local_first', false),
            'uses_server_invoicing' => $company ? $company->usesServerInvoicing() : null,
            'integration_inbox_path' => '/invoicing/stores/'.$store->id.'/integration-inbox',
            'invoices_path' => $company
                ? '/invoicing/companies/'.$company->id.'/invoices'
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertContact(StoreIntegration $integration, array $payload): CompanyContact
    {
        $company = $this->resolveCompany($integration);

        $email = trim((string) ($payload['email'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($email === '' && $name === '') {
            throw ValidationException::withMessages(['buyer' => ['Buyer name or email is required.']]);
        }

        $contact = null;
        if ($email !== '') {
            $contact = CompanyContact::query()
                ->where('company_id', $company->id)
                ->where('email', $email)
                ->first();
        }

        if (! $contact) {
            $contact = new CompanyContact(['company_id' => $company->id]);
        }

        $contact->fill([
            'name' => $name !== '' ? $name : ($contact->name ?: $email),
            'email' => $email ?: $contact->email,
            'registration_number' => (string) ($payload['ico'] ?? $contact->registration_number),
            'tax_id' => (string) ($payload['dic'] ?? $contact->tax_id),
            'vat_id' => (string) ($payload['ic_dph'] ?? $contact->vat_id),
            'street' => (string) ($payload['street'] ?? $contact->street),
            'city' => (string) ($payload['city'] ?? $contact->city),
            'postal_code' => (string) ($payload['zip'] ?? $contact->postal_code),
            'country' => (string) ($payload['country'] ?? $contact->country),
        ]);
        $contact->save();

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDocument(StoreIntegration $integration, array $payload): BusinessDocument|IntegrationDocumentInbox
    {
        $company = $this->resolveCompany($integration);
        $store = $integration->store;
        $user = $store->user;

        if (! $this->subscriptionService->canUseBusinessInvoicing($user)) {
            throw ValidationException::withMessages([
                'plan' => ['Business invoicing requires a PRO plan.'],
            ]);
        }

        if ($this->shouldUseInbox($company)) {
            $result = $this->inboxService->enqueueFromWoo($integration, $payload);
            $entry = IntegrationDocumentInbox::query()->findOrFail($result['inbox_id']);

            // Headless issue for paid orders when the company opted in - the
            // number lands in the response so the plugin can note it and the
            // customer email is queued immediately (P3 auto-issue).
            return $this->autoIssueService->maybeAutoIssue($company, $entry);
        }

        $wcOrderId = (int) ($payload['woocommerce_order_id'] ?? 0);
        if ($wcOrderId > 0) {
            $existing = BusinessDocument::query()
                ->where('company_id', $company->id)
                ->where('internal_note', 'like', '%woocommerce_order_id='.$wcOrderId.'%')
                ->where('status', '!=', BusinessDocumentStatus::Cancelled)
                ->first();
            if ($existing) {
                return $existing->load(['lines', 'contact', 'store']);
            }
        }

        $type = BusinessDocumentType::tryFrom((string) ($payload['type'] ?? 'invoice')) ?? BusinessDocumentType::Invoice;
        if (! $type->isMvpEnabled()) {
            throw ValidationException::withMessages(['type' => ['Document type not supported.']]);
        }

        $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
        $contact = $this->upsertContact($integration, $buyer);

        $appSettings = CompanyAppSettings::from($company->app_settings);
        $document = new BusinessDocument([
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'store_id' => $store->id,
            'type' => $type,
            'status' => BusinessDocumentStatus::Draft,
            'currency' => (string) ($payload['currency'] ?? $company->default_currency),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays((int) $appSettings->get('default_invoice_payment_terms_days', 14))->toDateString(),
            'delivery_date' => now()->toDateString(),
            'payment_btc_enabled' => (bool) $store->company_id,
            'payment_bank_enabled' => true,
            'internal_note' => $wcOrderId > 0 ? 'woocommerce_order_id='.$wcOrderId : null,
            'note_above_lines' => $wcOrderId > 0 ? 'WooCommerce order #'.$wcOrderId : null,
            'tags' => $wcOrderId > 0 ? ['woocommerce', 'wc_order:'.$wcOrderId] : ['woocommerce'],
        ]);

        $document->setRelation('company', $company);
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $normalized = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $normalized[] = [
                'name' => (string) ($line['name'] ?? 'Item'),
                'quantity' => (float) ($line['quantity'] ?? 1),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'tax_rate' => (float) ($line['tax_rate'] ?? 0),
                'unit' => (string) ($line['unit'] ?? 'pcs'),
            ];
        }

        $this->totalsCalculator->applyToDocument($document, $normalized, 0);
        $document->save();
        $this->syncLines($document, $normalized);

        return $document->fresh(['lines', 'contact', 'store', 'company']);
    }

    public function issueDocument(StoreIntegration $integration, BusinessDocument $document): BusinessDocument
    {
        $this->assertDocumentAccess($integration, $document);
        if ($document->status !== BusinessDocumentStatus::Draft) {
            return $document->load(['lines', 'contact', 'store', 'company']);
        }

        return $this->issueService->issue($document);
    }

    public function issueInboxEntry(
        StoreIntegration $integration,
        IntegrationDocumentInbox $inbox,
    ): IntegrationDocumentInbox {
        $this->assertInboxAccess($integration, $inbox);
        $company = $this->resolveCompany($integration);

        return $this->inboxService->issuePendingEntry($inbox, $company);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeIssuedInboxEntry(IntegrationDocumentInbox $entry): array
    {
        $payload = $entry->payload_json;
        $base = $this->inboxService->serializeEntry($entry);

        return [
            'id' => $entry->evolu_document_id,
            'inbox_id' => $entry->id,
            'evolu_document_id' => $entry->evolu_document_id,
            'number' => $payload['number'] ?? null,
            'status' => ! empty($payload['number']) ? 'issued' : $entry->status->value,
            'woocommerce_order_id' => $entry->woocommerce_order_id,
            'currency' => (string) ($payload['currency'] ?? 'EUR'),
            'payment_token' => null,
            'pdf_url' => null,
            'payment_url' => null,
            'summary' => $base['summary'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeInboxEntry(IntegrationDocumentInbox $entry): array
    {
        return $this->inboxService->serializeEntry($entry);
    }

    /**
     * Inbox response payload with auto-issue diagnostics: when the entry was
     * NOT issued, auto_issue_skipped names the reason (not_paid, no_profile,
     * document_type, issue_failed) so the plugin can record it on the order
     * instead of the order silently "staying in the inbox".
     *
     * @return array<string, mixed>
     */
    public function serializeInboxEntryWithDiagnostics(
        StoreIntegration $integration,
        IntegrationDocumentInbox $entry,
    ): array {
        $data = $this->inboxService->serializeEntry($entry);
        if (empty($data['number'])) {
            // An inbox entry can only exist for a resolved company -
            // createDocument (the sole flow reaching here) resolved it before
            // enqueueing, so this cannot throw.
            $company = $this->resolveCompany($integration);
            $data['auto_issue_skipped'] = $this->autoIssueService->skipReason($company, $entry);
        }

        return $data;
    }

    protected function shouldUseInbox(Company $company): bool
    {
        if (config('invoicing.woocommerce_inbox_mode')) {
            return true;
        }

        return ! $company->usesServerInvoicing();
    }

    /**
     * Render an auto-issued inbox document as PDF for the plugin (WC email
     * attachment path). Requires a stamped number and a synced profile.
     *
     * @return array{binary: string, filename: string}
     */
    public function renderInboxPdf(
        StoreIntegration $integration,
        IntegrationDocumentInbox $inbox,
        BusinessDocumentPdfService $pdfService,
        CompanyPdfFilenameBuilder $filenameBuilder,
    ): array {
        $this->assertInboxAccess($integration, $inbox);
        $company = $this->resolveCompany($integration);

        $payload = $inbox->payload_json;
        if (empty($payload['number'])) {
            throw ValidationException::withMessages([
                'document' => ['Document has no number yet - it was not auto-issued.'],
            ]);
        }

        $context = $this->autoIssueService->resolveProfileContext($company);
        if (! $context) {
            throw ValidationException::withMessages([
                'document' => ['Auto-issue profile is not configured for this company.'],
            ]);
        }

        $document = $this->autoIssueService->buildDocument($context['company'], $inbox, $context['profile']);

        return [
            'binary' => $pdfService->renderBinary($document),
            'filename' => $filenameBuilder->build($document),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeDocument(BusinessDocument $document): array
    {
        $pdfUrl = URL::to('/invoicing/companies/'.$document->company_id.'/documents/'.$document->id.'/pdf');
        $payUrl = $document->payment_token
            ? URL::to('/pay/i/'.$document->payment_token)
            : null;

        return [
            'id' => $document->id,
            'number' => $document->number,
            'status' => $document->status->value,
            'total' => $document->total,
            'currency' => $document->currency,
            'payment_token' => $document->payment_token,
            'pdf_url' => $pdfUrl,
            'payment_url' => $payUrl,
        ];
    }

    protected function resolveCompany(StoreIntegration $integration): Company
    {
        $company = $integration->company ?? $integration->store->company;
        if (! $company) {
            throw ValidationException::withMessages([
                'company' => ['Link a company to this store in Satflux invoicing settings.'],
            ]);
        }

        return $company;
    }

    protected function assertDocumentAccess(StoreIntegration $integration, BusinessDocument $document): void
    {
        $company = $this->resolveCompany($integration);
        if ($document->company_id !== $company->id) {
            abort(404);
        }
    }

    protected function assertInboxAccess(StoreIntegration $integration, IntegrationDocumentInbox $inbox): void
    {
        $integrationId = $inbox->store_integration_id;
        if ($integrationId !== $integration->id) {
            abort(404);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function syncLines(BusinessDocument $document, array $lines): void
    {
        $document->lines()->delete();
        foreach ($lines as $index => $line) {
            BusinessDocumentLine::create([
                'business_document_id' => $document->id,
                'sort_order' => $index,
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'] ?? 'pcs',
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'] ?? 0,
                'line_discount_percent' => 0,
                'line_total' => number_format((float) $line['quantity'] * (float) $line['unit_price'], 2, '.', ''),
            ]);
        }
    }
}
