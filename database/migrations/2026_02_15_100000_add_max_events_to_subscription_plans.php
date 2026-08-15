<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tickets: max events per store. Free=1, Pro=3, Enterprise=unlimited (null).
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('max_events')->nullable()->after('max_ln_addresses');
        });

        DB::table('subscription_plans')->where('code', 'free')->update(['max_events' => 1]);
        DB::table('subscription_plans')->where('code', 'pro')->update(['max_events' => 3]);
        DB::table('subscription_plans')->where('code', 'enterprise')->update(['max_events' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('max_events');
        });
    }
};
