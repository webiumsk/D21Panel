<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time 6-digit email codes (guest -> Free upgrade, wallet-connection
     * change). A table instead of the cache so attempt/send counters commit
     * atomically with the consume, rows survive cache:clear, the partial
     * unique index serialises "replace the active challenge" races, and the
     * encrypted payload (e.g. staged upgrade data) never leaves the DB.
     */
    public function up(): void
    {
        Schema::create('email_verification_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('email', 255);
            $table->string('code_hash', 64);
            $table->text('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
            $table->index('expires_at');
        });

        // Only one live challenge per (user, purpose). Same syntax on pgsql and sqlite.
        DB::statement(
            'CREATE UNIQUE INDEX email_verification_challenges_active_unique '
            .'ON email_verification_challenges (user_id, purpose) '
            .'WHERE consumed_at IS NULL AND superseded_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_challenges');
    }
};
