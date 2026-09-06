<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_connections', function (Blueprint $table) {
            // Payee attestation (PayeeAttestationService): Lightning node ids
            // allowed to sign this store's invoices, learned from a canary
            // invoice at baseline time or from the first settled payment.
            $table->json('payee_pubkeys')->nullable()->after('drift_details');
            $table->string('payee_learn_source', 32)->nullable()->after('payee_pubkeys');
            $table->timestamp('payee_learned_at')->nullable()->after('payee_learn_source');
            $table->timestamp('payee_mismatch_at')->nullable()->after('payee_learned_at');
            $table->json('payee_mismatch_details')->nullable()->after('payee_mismatch_at');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_connections', function (Blueprint $table) {
            $table->dropColumn(['payee_pubkeys', 'payee_learn_source', 'payee_learned_at', 'payee_mismatch_at', 'payee_mismatch_details']);
        });
    }
};
