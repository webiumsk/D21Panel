<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Store-scoped b-mail inbound address token (SEPA payment
            // confirmations). Lazily generated on first display.
            $table->string('bank_inbound_token', 16)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('bank_inbound_token');
        });
    }
};
