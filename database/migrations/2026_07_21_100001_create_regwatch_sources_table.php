<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Official sources the RegWatch cron monitors for legislative changes
        // (Slov-Lex, e-Sbirka, tax administrations...). The cron only reads
        // these and writes into regwatch_changes - never into regwatch_rules.
        Schema::create('regwatch_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('jurisdiction_id')->constrained('regwatch_jurisdictions')->cascadeOnDelete();
            // Natural key for idempotent seeding, e.g. "sk-slov-lex".
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('url');
            // legal_register | tax_authority (App\Enums\RegWatchSourceType).
            $table->string('type', 32);
            $table->boolean('active')->default(true);
            // Monitoring bookkeeping (filled by the future cron job): when the
            // source was last fetched and the sha256 of the last snapshot the
            // diff runs against.
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_snapshot_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['jurisdiction_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regwatch_sources');
    }
};
