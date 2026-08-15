<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Enums\CompanyWarehouseType;
use App\Models\Company;
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

class CompanyWarehouseTest extends TestCase
{
    use CreatesCompanyStock;
    use RefreshDatabase;

    private User $proUser;

    private Company $company;

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
    }

    #[Test]
    public function user_can_crud_warehouses(): void
    {
        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/warehouses", [
                'name' => 'Košice',
                'type' => CompanyWarehouseType::Own->value,
            ]);

        $create->assertCreated();
        $id = $create->json('data.id');

        $list = $this->actingAs($this->proUser)
            ->getJson("/api/invoicing/companies/{$this->company->id}/warehouses");
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, count($list->json('data')));

        $update = $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/warehouses/{$id}", [
                'name' => 'Košice pobočka',
            ]);
        $update->assertOk();
        $update->assertJsonPath('data.name', 'Košice pobočka');

        $delete = $this->actingAs($this->proUser)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/warehouses/{$id}");
        $delete->assertOk();
        $this->assertDatabaseMissing('company_warehouses', ['id' => $id]);

        $otherUser = User::factory()->create();
        Subscription::create([
            'user_id' => $otherUser->id,
            'plan_id' => SubscriptionPlan::where('code', 'pro')->firstOrFail()->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $otherCompany = Company::create([
            'user_id' => $otherUser->id,
            'legal_name' => 'Other s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);

        $this->actingAs($otherUser)
            ->getJson("/api/invoicing/companies/{$this->company->id}/warehouses")
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/warehouses", [
                'name' => 'Intruder',
                'type' => CompanyWarehouseType::Own->value,
            ])
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->getJson("/api/invoicing/companies/{$otherCompany->id}/warehouses")
            ->assertOk();
    }

    #[Test]
    public function invoice_deducts_from_selected_warehouse(): void
    {
        $default = $this->defaultWarehouse($this->company);
        $branch = CompanyWarehouse::create([
            'company_id' => $this->company->id,
            'name' => 'Bratislava',
            'type' => CompanyWarehouseType::Own,
            'deduct_on_issue' => true,
            'is_default' => false,
            'is_active' => true,
        ]);

        $item = $this->createStockItem($this->company, [
            'name' => 'Jablká',
            'sku' => 'Jab-123',
            'sale_unit_price' => 1.5,
        ], quantity: 0);

        app(CompanyStockBalanceService::class)
            ->setQuantity($default, $item, 10);
        app(CompanyStockBalanceService::class)
            ->setQuantity($branch, $item, 4);

        app(DocumentSequenceService::class)->seedDefaultsForCompany($this->company);

        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Jablká',
                        'quantity' => 2,
                        'unit' => 'kg',
                        'unit_price' => 1.5,
                        'company_stock_item_id' => $item->id,
                        'company_warehouse_id' => $branch->id,
                    ],
                ],
            ]);
        $create->assertCreated();
        $documentId = $create->json('data.id');

        $issue = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/issue");
        $issue->assertOk();

        $this->assertEquals(10.0, $this->stockQuantity($item, $default));
        $this->assertEquals(2.0, $this->stockQuantity($item, $branch));

        $otherUser = User::factory()->create();
        Subscription::create([
            'user_id' => $otherUser->id,
            'plan_id' => SubscriptionPlan::where('code', 'pro')->firstOrFail()->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        Company::create([
            'user_id' => $otherUser->id,
            'legal_name' => 'Rival s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'vat_payer' => false,
        ]);

        $this->actingAs($otherUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/issue")
            ->assertForbidden();

        $this->assertEquals(2.0, $this->stockQuantity($item, $branch));
    }

    #[Test]
    public function supplier_availability_warehouse_does_not_deduct_on_issue(): void
    {
        $dropship = CompanyWarehouse::create([
            'company_id' => $this->company->id,
            'name' => 'DE výrobca',
            'type' => CompanyWarehouseType::SupplierAvailability,
            'deduct_on_issue' => false,
            'is_default' => false,
            'is_active' => true,
        ]);

        $item = $this->createStockItem($this->company, [
            'name' => 'Dropship',
            'sku' => 'drop-1',
            'sale_unit_price' => 10,
        ], quantity: 0);

        app(CompanyStockBalanceService::class)
            ->setQuantity($dropship, $item, 50);

        app(DocumentSequenceService::class)->seedDefaultsForCompany($this->company);

        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Dropship',
                        'quantity' => 3,
                        'unit' => 'ks',
                        'unit_price' => 10,
                        'company_stock_item_id' => $item->id,
                        'company_warehouse_id' => $dropship->id,
                    ],
                ],
            ]);
        $create->assertCreated();
        $documentId = $create->json('data.id');

        $issue = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents/{$documentId}/issue");
        $issue->assertOk();

        $this->assertEquals(50.0, $this->stockQuantity($item, $dropship));
        $this->assertDatabaseMissing('company_stock_item_movements', [
            'business_document_id' => $documentId,
            'source' => 'document_issue',
        ]);

        $otherUser = User::factory()->create();
        Subscription::create([
            'user_id' => $otherUser->id,
            'plan_id' => SubscriptionPlan::where('code', 'pro')->firstOrFail()->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($otherUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/documents", [
                'type' => 'invoice',
                'currency' => 'EUR',
                'lines' => [
                    [
                        'name' => 'Dropship',
                        'quantity' => 1,
                        'unit' => 'ks',
                        'unit_price' => 10,
                        'company_stock_item_id' => $item->id,
                        'company_warehouse_id' => $dropship->id,
                    ],
                ],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function user_can_transfer_stock_between_warehouses(): void
    {
        $from = $this->defaultWarehouse($this->company);
        $to = CompanyWarehouse::create([
            'company_id' => $this->company->id,
            'name' => 'Pobočka',
            'type' => CompanyWarehouseType::Own,
            'deduct_on_issue' => true,
            'is_active' => true,
        ]);

        $item = $this->createStockItem($this->company, [
            'name' => 'Transfer item',
            'sku' => 'tr-1',
        ], quantity: 8);

        $response = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/stock-items/{$item->id}/transfer", [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'quantity' => 3,
            ]);

        $response->assertOk();
        $this->assertEquals(5.0, $this->stockQuantity($item, $from));
        $this->assertEquals(3.0, $this->stockQuantity($item, $to));

        $otherUser = User::factory()->create();
        Subscription::create([
            'user_id' => $otherUser->id,
            'plan_id' => SubscriptionPlan::where('code', 'pro')->firstOrFail()->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($otherUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/stock-items/{$item->id}/transfer", [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertEquals(5.0, $this->stockQuantity($item, $from));
        $this->assertEquals(3.0, $this->stockQuantity($item, $to));
    }

    #[Test]
    public function transfer_cannot_exceed_available_stock(): void
    {
        $from = $this->defaultWarehouse($this->company);
        $to = CompanyWarehouse::create([
            'company_id' => $this->company->id,
            'name' => 'Pobočka',
            'type' => CompanyWarehouseType::Own,
            'deduct_on_issue' => true,
            'is_active' => true,
        ]);

        $item = $this->createStockItem($this->company, [
            'name' => 'Transfer item',
            'sku' => 'tr-2',
        ], quantity: 2);

        $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/stock-items/{$item->id}/transfer", [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'quantity' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        // Nothing moved.
        $this->assertEquals(2.0, $this->stockQuantity($item, $from));
        $this->assertEquals(0.0, $this->stockQuantity($item, $to));
    }

    #[Test]
    public function warehouse_with_movement_history_cannot_be_deleted(): void
    {
        $warehouse = $this->defaultWarehouse($this->company);
        $item = $this->createStockItem($this->company, [
            'name' => 'History item',
            'sku' => 'hist-1',
        ], quantity: 1);
        // Record a real movement (balances alone do not create history),
        // then drain the balance so only the history blocks deletion.
        app(CompanyStockMovementService::class)
            ->recordManualChange($item, $warehouse, previousQuantity: 0);
        app(CompanyStockBalanceService::class)
            ->setQuantity($warehouse, $item, 0);

        $this->actingAs($this->proUser)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/warehouses/{$warehouse->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse']);

        $this->assertDatabaseHas('company_warehouses', ['id' => $warehouse->id]);
    }
}
