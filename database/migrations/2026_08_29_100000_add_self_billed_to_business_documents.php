<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-billing (docs plan Track D): a self-billed invoice is issued by the
 * CUSTOMER on behalf of the SUPPLIER (UBL InvoiceTypeCode 389, Peppol BIS
 * Self-Billing profile). The flag flips the supplier/customer roles in the
 * generated UBL; the document is still an ordinary invoice / credit note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_documents', function (Blueprint $table): void {
            $table->boolean('self_billed')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table): void {
            $table->dropColumn('self_billed');
        });
    }
};
