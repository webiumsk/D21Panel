<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Enums\CompanyStockMovementSource;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyStockItem;
use App\Models\CompanyWarehouse;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Invoicing\CompanyStockBalanceService;
use App\Services\Invoicing\CompanyStockMovementService;
use App\Services\Invoicing\DocumentSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCompanyStock;
use Tests\TestCase;

class CompanyStockDocumentMovementTest extends TestCase
{
    use CreatesCompanyStock;
    use RefreshDatabase;

    private User $proUser;

    private Company $company;

    private CompanyStockItem $stockItem;

    protected function setUp(): void
    {
        parent::setUp();

        $proPlan = SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);
        $this->proUser = User::factory()->create();
        Subscription::create([
            'user_id' => $this->proUser->id,
            'plan_id' => $proPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $this->company = Company::create([
            'user_id' => $this->proUser->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);

        app(DocumentSequenceService::class)->seedDefaultsForCompany($this->company);

        $this->stockItem = $this->createStockItem($this->company, [
            'name' => 'Jablká',
            'sku' => 'Jab-123',
            'unit' => 'kg',
            'sale_unit_price' => 1.5,
            'track_inventory' => true,
        ], quantity: 10);
    }

    #[Test]
    public function issuing_invoice_deducts_stock_and_cancel_restores_it(): void
    {
        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => 3,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $this->stockItem->id,
                    ],
                ],
            ]);
        $create->assertCreated();
        $documentId = $create->json('data.id');

        $issue = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/issue");
        $issue->assertOk();

        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));

        $this->assertDatabaseHas('company_stock_item_movements', [
            'company_stock_item_id' => $this->stockItem->id,
            'business_document_id' => $documentId,
            'source' => CompanyStockMovementSource::DocumentIssue->value,
            'quantity_delta' => -3,
        ]);

        $cancel = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/cancel");
        $cancel->assertOk();

        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));

        $this->assertDatabaseHas('company_stock_item_movements', [
            'company_stock_item_id' => $this->stockItem->id,
            'business_document_id' => $documentId,
            'source' => CompanyStockMovementSource::DocumentCancel->value,
            'quantity_delta' => 3,
        ]);
    }

    /** Creates + issues an invoice with 3 kg of the stock item, returns its id. */
    private function issueInvoiceWithStockLine(float $quantity = 3): string
    {
        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => $quantity,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $this->stockItem->id,
                    ],
                ],
            ]);
        $create->assertCreated();
        $documentId = $create->json('data.id');

        $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/issue")
            ->assertOk();

        return $documentId;
    }

    #[Test]
    public function deleting_an_issued_document_returns_its_stock(): void
    {
        $documentId = $this->issueInvoiceWithStockLine();
        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));

        $this->actingAs($this->proUser)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}")
            ->assertOk();

        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));
    }

    #[Test]
    public function cancelling_a_paid_document_returns_its_stock(): void
    {
        $documentId = $this->issueInvoiceWithStockLine();

        $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/mark-paid")
            ->assertOk();
        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));

        $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/cancel")
            ->assertOk();

        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));
    }

    #[Test]
    public function bulk_cancel_and_bulk_delete_return_stock(): void
    {
        $documentId = $this->issueInvoiceWithStockLine();
        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));

        $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/bulk", [
                'action' => 'cancel',
                'document_ids' => [$documentId],
            ])
            ->assertOk();
        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));

        $second = $this->issueInvoiceWithStockLine(quantity: 4);
        $this->assertEquals(6.0, $this->stockQuantity($this->stockItem));

        // Bulk delete refuses non-cancelled issued docs? canDelete governs -
        // cancel it first, stock is already back, delete must not double it.
        $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/bulk", [
                'action' => 'cancel',
                'document_ids' => [$second],
            ])
            ->assertOk();
        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));

        $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/bulk", [
                'action' => 'delete',
                'document_ids' => [$second],
            ])
            ->assertOk();
        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));
    }

    #[Test]
    public function editing_an_issued_document_rebuilds_its_stock_movements(): void
    {
        $documentId = $this->issueInvoiceWithStockLine();
        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));

        $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => 5,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $this->stockItem->id,
                    ],
                ],
            ])
            ->assertOk();

        // 10 - 5 after the rebuild, not 10 - 3 - 5.
        $this->assertEquals(5.0, $this->stockQuantity($this->stockItem));
        $this->assertDatabaseHas('company_stock_item_movements', [
            'business_document_id' => $documentId,
            'source' => CompanyStockMovementSource::DocumentAdjustment->value,
        ]);
    }

    #[Test]
    public function editing_an_issued_document_after_tracking_is_disabled_reverses_the_original_issue(): void
    {
        $documentId = $this->issueInvoiceWithStockLine();
        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));

        $this->stockItem->update(['track_inventory' => false]);

        $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => 5,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $this->stockItem->id,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));
        $this->assertDatabaseHas('company_stock_item_movements', [
            'business_document_id' => $documentId,
            'source' => CompanyStockMovementSource::DocumentAdjustment->value,
            'quantity_delta' => 3,
        ]);
    }

    #[Test]
    public function editing_an_issued_document_keeps_stock_in_the_original_inactive_warehouse(): void
    {
        $warehouse = CompanyWarehouse::create([
            'company_id' => $this->company->id,
            'name' => 'Retail shop',
            'type' => 'own',
            'deduct_on_issue' => true,
            'is_active' => true,
        ]);
        app(CompanyStockBalanceService::class)
            ->setQuantity($warehouse, $this->stockItem, 10);

        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => 3,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $this->stockItem->id,
                        'company_warehouse_id' => $warehouse->id,
                    ],
                ],
            ]);
        $create->assertCreated();
        $documentId = $create->json('data.id');

        $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/issue")
            ->assertOk();
        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem, $warehouse));
        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));

        $warehouse->update(['is_active' => false]);

        $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => 5,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $this->stockItem->id,
                        'company_warehouse_id' => $warehouse->id,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertEquals(5.0, $this->stockQuantity($this->stockItem, $warehouse));
        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));
    }

    #[Test]
    public function failed_issued_document_stock_rebuild_rolls_back_line_replacement(): void
    {
        $documentId = $this->issueInvoiceWithStockLine();
        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));

        $stockMovementService = \Mockery::mock(CompanyStockMovementService::class);
        $stockMovementService->shouldReceive('rebuildDocumentIssue')
            ->once()
            ->andThrow(new \RuntimeException('stock rebuild failed'));
        $this->instance(CompanyStockMovementService::class, $stockMovementService);

        try {
            $this->withoutExceptionHandling()
                ->actingAs($this->proUser)
                ->patchJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}", [
                    'type' => 'invoice',
                    'currency' => 'EUR',
                    'lines' => [
                        [
                            'name' => 'Jablká',
                            'quantity' => 5,
                            'unit' => 'kg',
                            'unit_price' => 1.5,
                            'company_stock_item_id' => $this->stockItem->id,
                        ],
                    ],
                ]);

            $this->fail('Expected stock rebuild failure to abort the document update.');
        } catch (\RuntimeException $e) {
            $this->assertSame('stock rebuild failed', $e->getMessage());
        }

        $document = BusinessDocument::with('lines')->findOrFail($documentId);

        $this->assertEquals(3.0, (float) $document->lines->firstOrFail()->quantity);
        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));
        $this->assertDatabaseMissing('company_stock_item_movements', [
            'business_document_id' => $documentId,
            'source' => CompanyStockMovementSource::DocumentAdjustment->value,
        ]);
    }

    #[Test]
    public function issuing_credit_note_returns_stock(): void
    {
        app(CompanyStockBalanceService::class)
            ->setQuantity($this->defaultWarehouse($this->company), $this->stockItem, 5);

        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents", [
                'type' => 'credit_note',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => 2,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $this->stockItem->id,
                    ],
                ],
            ]);
        $create->assertCreated();
        $documentId = $create->json('data.id');

        $issue = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/issue");
        $issue->assertOk();

        $this->assertEquals(7.0, $this->stockQuantity($this->stockItem));
    }

    #[Test]
    public function issuing_quote_does_not_change_stock(): void
    {
        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents", [
                'type' => 'quote',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => 3,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $this->stockItem->id,
                    ],
                ],
            ]);
        $create->assertCreated();
        $documentId = $create->json('data.id');

        $issue = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/issue");
        $issue->assertOk();

        $this->assertEquals(10.0, $this->stockQuantity($this->stockItem));
    }
}
