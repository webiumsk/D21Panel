<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-first companies cannot receive e-invoices as server expenses: the
 * receipt row itself becomes an inbox item (parsed draft + encrypted UBL)
 * that the client turns into an Evolu expense and then clears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efaktura_inbound_receipts', function (Blueprint $table) {
            // pending | imported | dismissed; existing (server-mode) rows are imported.
            $table->string('inbox_status', 16)->default('imported')->after('status');
            $table->uuid('evolu_expense_id')->nullable()->after('inbox_status');
            $table->json('draft_json')->nullable()->after('evolu_expense_id');
            $table->text('ubl_encrypted')->nullable()->after('draft_json');
            $table->string('external_number', 120)->nullable()->after('ubl_encrypted');
            $table->string('supplier_name')->nullable()->after('external_number');
            $table->decimal('total', 14, 2)->nullable()->after('supplier_name');
            $table->string('currency', 3)->nullable()->after('total');
            $table->timestamp('inbox_resolved_at')->nullable()->after('currency');

            $table->index(['company_id', 'inbox_status']);
        });
    }

    public function down(): void
    {
        Schema::table('efaktura_inbound_receipts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'inbox_status']);
            $table->dropColumn([
                'inbox_status',
                'evolu_expense_id',
                'draft_json',
                'ubl_encrypted',
                'external_number',
                'supplier_name',
                'total',
                'currency',
                'inbox_resolved_at',
            ]);
        });
    }
};
