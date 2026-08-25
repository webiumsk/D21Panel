<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute each number reservation to the user who made it
 * (docs/COMPANY_SHARING.md, "C5"). In a SHARED company several members allocate
 * invoice numbers from one sequence, so "who took number X" is the audit trail.
 * Nullable + nullOnDelete: legacy rows and deleted users leave it null, never
 * blocking allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_number_reservations', function (Blueprint $table): void {
            $table->foreignId('reserved_by_user_id')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_number_reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reserved_by_user_id');
        });
    }
};
