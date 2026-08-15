<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\EphemeralBtcpayCheckout;
use App\Models\Store;
use App\Models\User;
use App\Services\Invoicing\BusinessDocumentBtcPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rendering a PDF (auto-issue email, WC attachment, manual download) must
 * never leave stray BTCPay invoices behind - the QR resolver used to mint
 * unconditionally and never registered the checkout, making it invisible to
 * every dedupe (production 2026-07-15: a fresh "New" invoice appeared the
 * minute the paid order's PDF was rendered).
 */
class EphemeralPdfQrCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected BusinessDocumentBtcPayService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['btcpay_api_key' => 'test-key']);
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'btcpay_store_id' => 'btcpay-store-1',
        ]);
        $this->store->setRelation('user', $this->user);
        $this->service = app(BusinessDocumentBtcPayService::class);
    }

    protected function ephemeralDocument(float $total, float $amountPaid, string $status = 'issued'): BusinessDocument
    {
        $document = new BusinessDocument([
            'currency' => 'EUR',
            'payment_btc_enabled' => true,
        ]);
        $document->exists = false;
        $document->status = BusinessDocumentStatus::from($status);
        $document->total = number_format($total, 2, '.', '');
        $document->amount_paid = number_format($amountPaid, 2, '.', '');
        $document->store_id = $this->store->id;
        $document->setRelation('store', $this->store);
        $document->setRelation('company', Company::create([
            'user_id' => $this->user->id,
            'legal_name' => 'QR Test s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
        ]));

        return $document;
    }

    #[Test]
    public function paid_document_renders_without_minting_any_invoice(): void
    {
        Http::fake();

        $link = $this->service->qrCheckoutLinkForEphemeralRender(
            $this->ephemeralDocument(0.18, 0.18),
            $this->store,
            'evolu-doc-123',
        );

        $this->assertNull($link);
        Http::assertSentCount(0);
        $this->assertDatabaseCount('ephemeral_btcpay_checkouts', 0);
    }

    #[Test]
    public function existing_payable_checkout_is_reused_for_the_qr(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-pending' => Http::response([
                'id' => 'btcpay-inv-pending',
                'status' => 'New',
                'checkoutLink' => 'https://btcpay.example/i/pending',
            ], 200),
        ]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
            'amount' => 0.18,
            'currency' => 'EUR',
        ]);

        $link = $this->service->qrCheckoutLinkForEphemeralRender(
            $this->ephemeralDocument(0.18, 0),
            $this->store,
            'evolu-doc-123',
        );

        $this->assertSame('https://btcpay.example/i/pending', $link);
        $this->assertDatabaseCount('ephemeral_btcpay_checkouts', 1);
    }

    #[Test]
    public function unpaid_document_mints_once_and_registers_the_checkout(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-new' => Http::response([
                'id' => 'btcpay-inv-new',
                'status' => 'New',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
            '*' => Http::response([
                'id' => 'btcpay-inv-new',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
        ]);

        $link = $this->service->qrCheckoutLinkForEphemeralRender(
            $this->ephemeralDocument(0.18, 0),
            $this->store,
            'evolu-doc-123',
        );

        $this->assertSame('https://btcpay.example/i/new', $link);
        // The mint is registered - a later view/render/create finds it.
        $this->assertDatabaseHas('ephemeral_btcpay_checkouts', [
            'btcpay_invoice_id' => 'btcpay-inv-new',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
        ]);

        // A second render (email + WC attachment) reuses the registered
        // checkout - exactly one create call ever reaches BTCPay.
        $secondLink = $this->service->qrCheckoutLinkForEphemeralRender(
            $this->ephemeralDocument(0.18, 0),
            $this->store,
            'evolu-doc-123',
        );

        $this->assertSame($link, $secondLink);
        $this->assertDatabaseCount('ephemeral_btcpay_checkouts', 1);
        Http::assertSentCount(2); // 1x create + 1x status fetch, never 2x create
    }

    #[Test]
    public function paid_status_alone_suppresses_the_qr_even_without_amount_paid(): void
    {
        Http::fake();

        // A paid document whose snapshot lacks amount_paid (defensive: every
        // current paid-marking path writes it, but status is authoritative).
        $link = $this->service->qrCheckoutLinkForEphemeralRender(
            $this->ephemeralDocument(0.18, 0, 'paid'),
            $this->store,
            'evolu-doc-123',
        );

        $this->assertNull($link);
        Http::assertSentCount(0);
    }

    #[Test]
    public function render_without_a_dedupe_key_never_mints(): void
    {
        Http::fake();

        $link = $this->service->qrCheckoutLinkForEphemeralRender(
            $this->ephemeralDocument(0.18, 0),
            $this->store,
            null,
        );

        $this->assertNull($link);
        Http::assertSentCount(0);
        $this->assertDatabaseCount('ephemeral_btcpay_checkouts', 0);
    }

    #[Test]
    public function unknown_btcpay_state_renders_without_qr_instead_of_minting(): void
    {
        Http::fake([
            '*/invoices/btcpay-inv-pending' => Http::response(null, 500),
            '*' => Http::response([
                'id' => 'btcpay-inv-new',
                'checkoutLink' => 'https://btcpay.example/i/new',
            ], 200),
        ]);

        EphemeralBtcpayCheckout::query()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'btcpay_invoice_id' => 'btcpay-inv-pending',
            'evolu_document_id' => 'evolu-doc-123',
            'status' => 'pending',
            'amount' => 0.18,
            'currency' => 'EUR',
        ]);

        $link = $this->service->qrCheckoutLinkForEphemeralRender(
            $this->ephemeralDocument(0.18, 0),
            $this->store,
            'evolu-doc-123',
        );

        $this->assertNull($link);
        $this->assertDatabaseMissing('ephemeral_btcpay_checkouts', [
            'btcpay_invoice_id' => 'btcpay-inv-new',
        ]);
    }
}
