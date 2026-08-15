<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip if column already exists (added by previous migration)
        if (Schema::hasColumn('users', 'btcpay_user_id')) {
            return;
        }

        try {
            Schema::table('users', function (Blueprint $table) {
                $table->string('btcpay_user_id')->nullable()->after('lightning_public_key');
                $table->index('btcpay_user_id');
            });
        } catch (QueryException $e) {
            // Column already exists, skip
            if (str_contains($e->getMessage(), 'Duplicate column')) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['btcpay_user_id']);
            $table->dropColumn('btcpay_user_id');
        });
    }
};
