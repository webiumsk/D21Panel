<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regwatch_changes', function (Blueprint $table) {
            // Admin who moved the change out of 'new' (users.id is bigint,
            // unlike the uuid RegWatch tables).
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('regwatch_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
        });
    }
};
