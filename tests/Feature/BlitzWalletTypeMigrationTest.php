<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BlitzWalletTypeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_blitz_wallet_type_rollback_refuses_live_blitz_stores(): void
    {
        $store = Store::factory()->create([
            'wallet_type' => 'blitz',
        ]);

        $migration = require database_path('migrations/2026_08_05_120000_add_blitz_to_stores_wallet_type_enum.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('still use Blitz');

        try {
            $migration->down();
        } finally {
            $this->assertSame('blitz', $store->fresh()->wallet_type);
        }
    }
}
