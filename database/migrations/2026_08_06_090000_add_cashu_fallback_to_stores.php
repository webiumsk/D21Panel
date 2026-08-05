<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // CashuMelt runs in parallel with the Lightning backend as a fallback:
            // the plugin stays enabled with this payout address while wallet_type
            // remains blink/blitz/aqua_boltz/nwc.
            $table->boolean('cashu_fallback_enabled')->default(false);
            $table->string('cashu_fallback_address', 320)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['cashu_fallback_enabled', 'cashu_fallback_address']);
        });
    }
};
