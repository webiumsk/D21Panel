<?php

namespace Tests\Feature;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessDocumentType;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLine;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCompanyStock;
use Tests\TestCase;

class CompanyStockItemCrudTest extends TestCase
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
    public function user_can_crud_stock_items_for_own_company(): void
    {
        $create = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/stock-items", [
                'name' => 'Jablká',
                'sku' => 'Jab-123',
                'unit' => 'kg',
                'quantity_on_hand' => 13,
                'sale_unit_price' => 1.5,
                'purchase_unit_price' => 1,
                'purchase_currency' => 'EUR',
            ]);

        $create->assertCreated();
        $id = $create->json('data.id');
        $create->assertJsonPath('data.quantity_on_hand', 13);

        $list = $this->actingAs($this->proUser)
            ->getJson("/api/invoicing/companies/{$this->company->id}/stock-items?q=jab");
        $list->assertOk();
        $list->assertJsonPath('meta.item_count', 1);
        $list->assertJsonPath('meta.sale_value_total', 19.5);

        $show = $this->actingAs($this->proUser)
            ->getJson("/api/invoicing/companies/{$this->company->id}/stock-items/{$id}");
        $show->assertOk();
        $show->assertJsonPath('data.name', 'Jablká');

        $update = $this->actingAs($this->proUser)
            ->patchJson("/api/invoicing/companies/{$this->company->id}/stock-items/{$id}", [
                'name' => 'Jablká Red',
                'sku' => 'Jab-123',
                'quantity_on_hand' => 10,
            ]);
        $update->assertOk();
        $update->assertJsonPath('data.name', 'Jablká Red');

        $delete = $this->actingAs($this->proUser)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/stock-items/{$id}");
        $delete->assertOk();
        $this->assertDatabaseMissing('company_stock_items', ['id' => $id]);
    }

    #[Test]
    public function sku_must_be_unique_within_company(): void
    {
        $this->createStockItem($this->company, [
            'name' => 'Existing',
            'sku' => 'dup-sku',
        ]);

        $response = $this->actingAs($this->proUser)
            ->postJson("/api/invoicing/companies/{$this->company->id}/stock-items", [
                'name' => 'Another',
                'sku' => 'dup-sku',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sku']);
    }

    #[Test]
    public function stock_item_used_on_document_cannot_be_deleted(): void
    {
        $item = $this->createStockItem($this->company, [
            'name' => 'Used item',
            'sku' => 'used-1',
        ], quantity: 5);

        BusinessDocumentLine::create([
            'business_document_id' => BusinessDocument::create([
                'company_id' => $this->company->id,
                'type' => BusinessDocumentType::Invoice,
                'status' => BusinessDocumentStatus::Draft,
                'total' => 10,
                'currency' => 'EUR',
                'lines' => [],
            ])->id,
            'company_stock_item_id' => $item->id,
            'name' => 'Used item',
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10,
        ]);

        $response = $this->actingAs($this->proUser)
            ->deleteJson("/api/invoicing/companies/{$this->company->id}/stock-items/{$item->id}");

        $response->assertStatus(422);
    }

    #[Test]
    public function suggester_excludes_items_marked_exclude_from_suggester(): void
    {
        $this->createStockItem($this->company, [
            'name' => 'Hidden pears',
            'sku' => 'pear-hidden',
            'exclude_from_suggester' => true,
        ]);
        $this->createStockItem($this->company, [
            'name' => 'Visible pears',
            'sku' => 'pear-visible',
        ]);

        $response = $this->actingAs($this->proUser)
            ->getJson("/api/invoicing/companies/{$this->company->id}/stock-items/search?q=pear");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.sku', 'pear-visible');
    }
}
