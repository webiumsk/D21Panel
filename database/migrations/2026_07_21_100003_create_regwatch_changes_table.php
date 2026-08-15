<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RegWatch changelog - the ONLY table the monitoring cron writes to
        // (status 'new'). A human reviews each row before anything reaches
        // regwatch_rules: new -> reviewed -> applied/dismissed.
        Schema::create('regwatch_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // restrictOnDelete: the changelog is an audit trail - a source
            // with detection history cannot be hard-deleted (deactivate it
            // via regwatch_sources.active instead).
            $table->foreignUuid('source_id')->constrained('regwatch_sources')->restrictOnDelete();
            $table->foreignUuid('rule_id')->nullable()->constrained('regwatch_rules')->nullOnDelete();
            // App\Enums\RegWatchChangeStatus.
            $table->string('status', 16)->default('new');
            $table->text('summary')->nullable();
            $table->text('diff')->nullable();
            // Relevance classification of the diff (Claude API output in the
            // future monitoring pipeline - classification only, never facts).
            $table->json('classification_json')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'status']);
            $table->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regwatch_changes');
    }
};
