<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Current BTCPay requires currentPassword for email changes on
     * PUT /api/v1/users/me (there is no admin route). Store the generated
     * guest BTCPay password (encrypted) so the email sync can supply it.
     * Accounts provisioned before this column stay null - their BTCPay
     * password was generated and discarded, so their BTCPay account email
     * cannot be changed via API.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('btcpay_password')->nullable()->after('btcpay_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('btcpay_password');
        });
    }
};
