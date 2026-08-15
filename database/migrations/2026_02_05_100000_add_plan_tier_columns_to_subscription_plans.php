<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds code, billing_period, max_ln_addresses for tier definitions.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->after('id');
            $table->string('billing_period', 16)->default('year')->after('price_eur');
            $table->integer('max_ln_addresses')->nullable()->after('max_api_keys');
        });

        // Backfill code from name for existing rows
        foreach (DB::table('subscription_plans')->get() as $row) {
            DB::table('subscription_plans')->where('id', $row->id)->update([
                'code' => strtolower($row->name),
            ]);
        }

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'billing_period', 'max_ln_addresses']);
        });
    }
};
