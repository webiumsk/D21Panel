<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_connections', function (Blueprint $table) {
            // Per payment-method hashes of the BTCPay config Satflux last wrote
            // (WalletConfigIntegrityService); null until the first baseline.
            $table->json('config_fingerprint')->nullable()->after('configuration_source');
            // Encrypted canonical configs behind the fingerprint, so a drift can
            // show (masked) what was expected vs. what BTCPay holds now.
            $table->text('config_snapshot')->nullable()->after('config_fingerprint');
            $table->timestamp('config_verified_at')->nullable()->after('config_fingerprint');
            $table->timestamp('drift_detected_at')->nullable()->after('config_verified_at');
            $table->json('drift_details')->nullable()->after('drift_detected_at');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_connections', function (Blueprint $table) {
            $table->dropColumn(['config_fingerprint', 'config_snapshot', 'config_verified_at', 'drift_detected_at', 'drift_details']);
        });
    }
};
