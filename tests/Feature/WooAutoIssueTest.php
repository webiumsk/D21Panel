<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Jobs\SendWooAutoInvoiceEmail;
use App\Mail\BusinessDocumentEmail;
use App\Models\Company;
use App\Models\CompanyAutoIssueProfile;
use App\Models\CompanyDocumentSequence;
use App\Models\DocumentNumberReservation;
use App\Models\IntegrationDocumentInbox;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\User;
use App\Services\Integrations\IntegrationAutoIssueService;
use App\Services\Invoicing\BusinessDocumentEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WooAutoIssueTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Store $store;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Local-first company → WooCommerce documents go through the inbox.
        config(['invoicing.woocommerce_inbox_mode' => true]);

        $this->user = User::factory()->create(['role' => 'enterprise']);
        $this->company = Company::create([
            'user_id' => $this->user->id,
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
        ]);
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $credentials = StoreIntegration::createForStore($this->store);
        $this->token = $credentials['token'];

        // Merchant's LOCAL series format synced to the server series row -
        // auto-issued numbers must use it, never the server default INV....
        CompanyDocumentSequence::create([
            'company_id' => $this->company->id,
            'document_type' => 'invoice',
            'name' => 'Faktúry',
            'format' => 'FVYYYYNNNN',
            'reset_period' => 'yearly',
            'is_default' => true,
            'period_key' => now()->format('Y'),
            'last_number' => 0,
        ]);
    }

    protected function createProfile(bool $autoEmail = true): CompanyAutoIssueProfile
    {
        return CompanyAutoIssueProfile::create([
            'company_id' => $this->company->id,
            'profile_json' => [
                'company' => [
                    'legal_name' => 'Webium s.r.o.',
                    'street' => 'Hlavná 1',
                    'city' => 'Bratislava',
                    'postal_code' => '81101',
                    'country' => 'SK',
                    'iban' => 'SK3112000000198742637541',
                ],
            ],
            'auto_email' => $autoEmail,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function paidOrderPayload(int $orderId = 1001): array
    {
        return [
            'type' => 'invoice',
            'woocommerce_order_id' => $orderId,
            'currency' => 'EUR',
            'buyer' => [
                'name' => 'Ján Kupujúci',
                'email' => 'buyer@example.com',
                'street' => 'Nákupná 5',
                'city' => 'Košice',
                'zip' => '04001',
                'country' => 'SK',
            ],
            'lines' => [
                ['name' => 'Produkt A', 'quantity' => 2, 'unit_price' => 10.5],
                ['name' => 'Shipping', 'quantity' => 1, 'unit_price' => 4.0],
            ],
            'payment_method' => 'stripe',
            'is_paid' => true,
            'paid_at' => now()->toIso8601String(),
            'order_total' => 25.0,
        ];
    }

    public function test_paid_order_with_profile_is_auto_issued_with_local_format_and_email_queued(): void
    {
        Queue::fake();
        $this->createProfile();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->assertCreated()
            ->assertJsonPath('data.number', 'FV'.now()->format('Y').'0001')
            ->assertJsonPath('data.auto_issued', true)
            ->assertJsonPath('data.email_queued', true)
            ->assertJsonPath('data.pdf_available', true)
            ->assertJsonPath('data.status', 'pending');

        $inboxId = $response->json('data.inbox_id');
        $entry = IntegrationDocumentInbox::findOrFail($inboxId);
        $this->assertSame('FV'.now()->format('Y').'0001', $entry->payload_json['number']);
        $this->assertNotEmpty($entry->payload_json['auto_issued_at']);

        // Number came from the shared reservation allocator (confirmed),
        // keyed by the WooCommerce ORDER so re-sends reuse the same number.
        $reservation = DocumentNumberReservation::query()
            ->where('company_id', $this->company->id)
            ->first();
        $this->assertNotNull($reservation);
        $this->assertStringStartsWith('woo-order:', $reservation->issue_request_id);
        $this->assertStringEndsWith(':1001', $reservation->issue_request_id);
        $this->assertSame(DocumentNumberReservation::STATUS_CONFIRMED, $reservation->status);

        Queue::assertPushed(SendWooAutoInvoiceEmail::class, fn ($job) => $job->inboxEntryId === $inboxId);
    }

    public function test_auto_issued_document_keeps_jurisdiction_pdf_locale_for_rendering(): void
    {
        Queue::fake();
        $this->company->update(['jurisdiction' => CompanyJurisdiction::EuDe]);
        $profile = $this->createProfile();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->assertCreated()
            ->assertJsonPath('data.payload.pdf_locale', 'de');

        $entry = IntegrationDocumentInbox::findOrFail($response->json('data.inbox_id'));
        $this->company->refresh();
        $document = app(IntegrationAutoIssueService::class)
            ->buildDocument($this->company, $entry, $profile);

        $this->assertSame('de', $document->pdf_locale);
    }

    public function test_local_high_counter_from_profile_raises_the_series_floor(): void
    {
        Queue::fake();
        $profile = $this->createProfile();
        $profile->update([
            'profile_json' => array_merge($profile->profile_json, [
                'local_high_counters' => ['invoice' => 41],
            ]),
        ]);

        // ensureCounterSynced derives the counter from SERVER documents (none
        // for a local-first company) - without the synced local high counter
        // this would hand out ...0001 and collide with locally issued numbers.
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->assertCreated()
            ->assertJsonPath('data.number', 'FV'.now()->format('Y').'0042');
    }

    public function test_unpaid_proforma_is_auto_issued_from_the_pf_series_without_email(): void
    {
        Queue::fake();
        $this->createProfile(autoEmail: false);

        // Deferred payment (COD / bank transfer): the proforma is the payment
        // REQUEST - it is issued precisely while unpaid.
        $payload = $this->paidOrderPayload();
        $payload['type'] = 'proforma';
        $payload['is_paid'] = false;
        // The plugin serializes unpaid orders with an explicit null and COD
        // orders with an empty BTCPay id (arrives as null via
        // convertEmptyStringsToNull) - both must pass validation.
        $payload['paid_at'] = null;
        $payload['btcpay_invoice_id'] = '';
        $payload['payment_method'] = 'cod';

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $payload)
            ->assertCreated()
            ->assertJsonPath('data.number', 'PF'.now()->format('Y').'0001')
            ->assertJsonPath('data.auto_issued', true)
            ->assertJsonPath('data.email_queued', false);

        $entry = IntegrationDocumentInbox::findOrFail($response->json('data.inbox_id'));
        $this->assertSame('proforma', $entry->document_type);
        Queue::assertNothingPushed();
    }

    public function test_one_order_carries_a_proforma_and_a_linked_final_invoice(): void
    {
        Queue::fake();
        $this->createProfile(autoEmail: false);

        // Stage 1: order reaches Processing - deferred gateway sends a proforma.
        $proformaPayload = $this->paidOrderPayload();
        $proformaPayload['type'] = 'proforma';
        $proformaPayload['is_paid'] = false;
        unset($proformaPayload['paid_at']);

        $proforma = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $proformaPayload)
            ->assertCreated()
            ->json('data');
        $this->assertSame('PF'.now()->format('Y').'0001', $proforma['number']);

        // Stage 2: order completes - the final INVOICE arrives for the SAME
        // order, linked to the proforma. Dedupe is per type, so the pending
        // proforma must NOT be returned.
        $invoicePayload = $this->paidOrderPayload();
        $invoicePayload['source_evolu_document_id'] = $proforma['evolu_document_id'];
        $invoicePayload['source_document_number'] = $proforma['number'];

        $invoice = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $invoicePayload)
            ->assertCreated()
            ->json('data');

        $this->assertNotSame($proforma['inbox_id'], $invoice['inbox_id']);
        $this->assertSame('FV'.now()->format('Y').'0001', $invoice['number']);
        $this->assertSame(
            $proforma['evolu_document_id'],
            $invoice['payload']['source_evolu_document_id'] ?? null,
        );
        // The proforma reference lands on the document itself (SK company →
        // Slovak wording) so the PDF and the imported local doc carry it.
        $this->assertStringContainsString(
            'Uhradené zálohovou faktúrou '.$proforma['number'],
            (string) ($invoice['payload']['note_above_lines'] ?? ''),
        );

        // Two reservations on separate series: the invoice keeps the
        // historical un-suffixed key, the proforma is type-suffixed.
        $keys = DocumentNumberReservation::query()->pluck('issue_request_id')->all();
        $this->assertCount(2, $keys);
        $this->assertContains('woo-order:'.IntegrationDocumentInbox::findOrFail($invoice['inbox_id'])->store_integration_id.':1001', $keys);
        $this->assertContains('woo-order:'.IntegrationDocumentInbox::findOrFail($invoice['inbox_id'])->store_integration_id.':1001:pf', $keys);
    }

    public function test_unpaid_order_is_not_auto_issued_and_names_the_reason(): void
    {
        Queue::fake();
        $this->createProfile();

        $payload = $this->paidOrderPayload();
        $payload['is_paid'] = false;

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $payload)
            ->assertCreated()
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.auto_issued', false)
            ->assertJsonPath('data.auto_issue_skipped', 'not_paid');

        Queue::assertNothingPushed();
    }

    public function test_profile_on_bridge_company_of_same_user_is_used_with_its_series(): void
    {
        Queue::fake();

        // Production scenario 2026-07-14: the integration points at a legacy
        // company row while the client synced the profile (and allocates
        // numbers) on a bridge company with a different identity. The owner
        // has exactly ONE profile - auto-issue must use it, including ITS
        // number series.
        $bridgeCompany = Company::create([
            'user_id' => $this->user->id,
            'legal_name' => 'Webium bridge s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
        ]);
        CompanyDocumentSequence::create([
            'company_id' => $bridgeCompany->id,
            'document_type' => 'invoice',
            'name' => 'FA',
            'format' => 'FAYYYYNNNN',
            'reset_period' => 'yearly',
            'is_default' => true,
            'period_key' => now()->format('Y'),
            'last_number' => 0,
        ]);
        CompanyAutoIssueProfile::create([
            'company_id' => $bridgeCompany->id,
            'profile_json' => [
                'company' => ['legal_name' => 'Webium bridge s.r.o.'],
                'local_high_counters' => ['invoice' => 70],
            ],
            'auto_email' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->assertCreated()
            ->assertJsonPath('data.number', 'FA'.now()->format('Y').'0071')
            ->assertJsonPath('data.auto_issued', true)
            ->assertJsonPath('data.email_queued', true);

        // The reservation lives on the BRIDGE company - the same series the
        // merchant's browser allocates on.
        $reservation = DocumentNumberReservation::query()->firstOrFail();
        $this->assertSame($bridgeCompany->id, $reservation->company_id);
        $this->assertNotNull($response->json('data.inbox_id'));
        Queue::assertPushed(SendWooAutoInvoiceEmail::class, 1);
    }

    public function test_two_profiles_none_on_integration_company_reports_ambiguous(): void
    {
        Queue::fake();

        foreach (['Firma A s.r.o.', 'Firma B s.r.o.'] as $name) {
            $other = Company::create([
                'user_id' => $this->user->id,
                'legal_name' => $name,
                'jurisdiction' => CompanyJurisdiction::EuSk,
                'default_currency' => 'EUR',
            ]);
            CompanyAutoIssueProfile::create([
                'company_id' => $other->id,
                'profile_json' => ['company' => ['legal_name' => $name]],
                'auto_email' => true,
            ]);
        }

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->assertCreated()
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.auto_issue_skipped', 'ambiguous_profile');

        Queue::assertNothingPushed();
    }

    public function test_paid_order_without_profile_stays_pending_and_names_the_reason(): void
    {
        Queue::fake();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->assertCreated()
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.auto_issued', false)
            ->assertJsonPath('data.auto_issue_skipped', 'no_profile');

        Queue::assertNothingPushed();
    }

    public function test_resent_order_is_idempotent_and_burns_no_second_number(): void
    {
        Queue::fake();
        $this->createProfile();

        $first = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->json('data');

        $second = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->json('data');

        $this->assertSame($first['inbox_id'], $second['inbox_id']);
        $this->assertSame($first['number'], $second['number']);
        $this->assertSame(1, DocumentNumberReservation::query()->where('company_id', $this->company->id)->count());
        Queue::assertPushed(SendWooAutoInvoiceEmail::class, 1);
    }

    public function test_resend_after_import_reuses_the_same_number_and_sends_no_second_email(): void
    {
        Queue::fake();
        $this->createProfile();

        $first = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->json('data');

        // Merchant imported the entry in the browser - the server row is gone.
        IntegrationDocumentInbox::findOrFail($first['inbox_id'])->delete();

        // The plugin re-sends the same order (status re-trigger / manual
        // re-send): a NEW inbox entry appears, but the reservation is keyed
        // by the WooCommerce order, so the SAME number is stamped and no
        // second customer email is queued.
        $second = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->json('data');

        $this->assertNotSame($first['inbox_id'], $second['inbox_id']);
        $this->assertSame($first['number'], $second['number']);
        $this->assertFalse($second['email_queued']);
        $this->assertSame(1, DocumentNumberReservation::query()->where('company_id', $this->company->id)->count());
        Queue::assertPushed(SendWooAutoInvoiceEmail::class, 1);
    }

    public function test_email_job_sends_via_company_mailer_and_stamps_evidence(): void
    {
        Mail::fake();
        $this->createProfile();

        $inboxId = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->json('data.inbox_id');

        (new SendWooAutoInvoiceEmail($inboxId))->handle(
            app(IntegrationAutoIssueService::class),
            app(BusinessDocumentEmailService::class),
        );

        Mail::assertSent(BusinessDocumentEmail::class);

        $entry = IntegrationDocumentInbox::findOrFail($inboxId);
        $this->assertNotEmpty($entry->payload_json['emailed_at']);
    }

    public function test_email_job_tolerates_imported_entry(): void
    {
        $this->createProfile();

        // Entry deleted (imported) before the queued email ran.
        (new SendWooAutoInvoiceEmail((string) Str::uuid()))->handle(
            app(IntegrationAutoIssueService::class),
            app(BusinessDocumentEmailService::class),
        );

        $this->assertTrue(true); // no exception - skip path
    }

    public function test_pdf_endpoint_renders_auto_issued_document(): void
    {
        Queue::fake();
        $this->createProfile();

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->json('data');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->get('/api/integrations/woocommerce/documents/'.$data['evolu_document_id'].'/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_pdf_endpoint_rejects_unnumbered_entry(): void
    {
        Queue::fake();

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/integrations/woocommerce/documents', $this->paidOrderPayload())
            ->json('data');

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/integrations/woocommerce/documents/'.$data['evolu_document_id'].'/pdf')
            ->assertStatus(422);
    }

    public function test_profile_endpoints_enforce_company_ownership(): void
    {
        $payload = [
            'auto_email' => true,
            'company' => ['legal_name' => 'Webium s.r.o.'],
        ];

        $this->actingAs($this->user)
            ->putJson('/api/invoicing/companies/'.$this->company->id.'/auto-issue-profile', $payload)
            ->assertOk()
            ->assertJsonPath('data.auto_email', true);

        $this->actingAs($this->user)
            ->getJson('/api/invoicing/companies/'.$this->company->id.'/auto-issue-profile')
            ->assertOk()
            ->assertJsonPath('data.company_id', $this->company->id);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->getJson('/api/invoicing/companies/'.$this->company->id.'/auto-issue-profile')
            ->assertForbidden();

        $this->actingAs($this->user)
            ->deleteJson('/api/invoicing/companies/'.$this->company->id.'/auto-issue-profile')
            ->assertNoContent();

        $this->actingAs($this->user)
            ->getJson('/api/invoicing/companies/'.$this->company->id.'/auto-issue-profile')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
