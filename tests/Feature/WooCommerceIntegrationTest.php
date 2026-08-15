<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WooCommerceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_requires_valid_token(): void
    {
        $this->getJson('/api/integrations/woocommerce/connection')
            ->assertStatus(401);
    }

    public function test_connection_returns_store_info(): void
    {
        $user = User::factory()->create(['role' => 'enterprise']);
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Test Co',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
        ]);
        $store = Store::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $credentials = StoreIntegration::createForStore($store);

        $this->withHeader('Authorization', 'Bearer '.$credentials['token'])
            ->getJson('/api/integrations/woocommerce/connection')
            ->assertOk()
            ->assertJsonPath('data.store.id', $store->id)
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.integration_inbox_path', '/invoicing/stores/'.$store->id.'/integration-inbox')
            ->assertJsonPath('data.inbox_mode', false);
    }

    public function test_connect_exchange_returns_credentials_once(): void
    {
        $payload = [
            'btcpay_url' => 'https://btcpay.example',
            'api_key' => 'test-api-key',
            'store_id' => 'store123',
            'integration_token' => 'sfwc_testtoken',
            'integration_secret' => 'secret',
            'invoicing_enabled' => '1',
        ];
        $code = bin2hex(random_bytes(32));
        Cache::put('woocommerce_connect_'.$code, $payload, now()->addMinutes(10));

        $this->getJson('/api/integrations/woocommerce/oauth-exchange?code='.$code)
            ->assertOk()
            ->assertJsonPath('data.integration_token', 'sfwc_testtoken');

        $this->getJson('/api/integrations/woocommerce/oauth-exchange?code='.$code)
            ->assertStatus(404);
    }
}
