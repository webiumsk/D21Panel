<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RegWatch source of truth for tax/legal rules. Rows are edited ONLY
        // by a human after review (docs/LEGAL.md): rule_text stays a seeded
        // placeholder until verified_on carries the date a person verified it
        // against source_url. The monitoring cron must never write here.
        Schema::create('regwatch_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('jurisdiction_id')->constrained('regwatch_jurisdictions')->cascadeOnDelete();
            $table->foreignUuid('source_id')->nullable()->constrained('regwatch_sources')->nullOnDelete();
            // Natural key for idempotent seeding, e.g. "sk-vat-registration".
            $table->string('slug', 64)->unique();
            // Phase-1 topic taxonomy (App\Enums\RegWatchTopic).
            $table->string('topic', 32);
            $table->string('title');
            $table->text('rule_text');
            $table->string('source_url');
            // Date a human verified rule_text against source_url; NULL means
            // the row is an unverified placeholder and must not be relied on.
            $table->date('verified_on')->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamps();

            $table->index(['jurisdiction_id', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regwatch_rules');
    }
};
