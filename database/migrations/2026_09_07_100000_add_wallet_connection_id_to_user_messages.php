<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_messages', function (Blueprint $table) {
            // Security messages raised for a wallet connection carry its id so
            // an incident's messages can be found (and purged) precisely.
            $table->uuid('wallet_connection_id')->nullable()->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_messages', function (Blueprint $table) {
            $table->dropIndex(['wallet_connection_id']);
            $table->dropColumn('wallet_connection_id');
        });
    }
};
