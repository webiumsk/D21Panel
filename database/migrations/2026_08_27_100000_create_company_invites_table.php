<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending invites into a shared company (docs/COMPANY_SHARING.md, "C4").
 *
 * The company's SharedOwner secret reaches the invitee sealed to their
 * long-term public key (`sealed_secret_json`, opaque to the server) or, for
 * the link fallback, out-of-band in a URL fragment (`sealed_secret_json` null,
 * only the token lives here). Accepting an invite writes a `company_members`
 * row; the invite itself is one-time (token hashed at rest) and expiring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('role', 16);                      // accountant | member (never owner)
            $table->string('mode', 8);                       // sealed | link
            $table->string('invited_email')->nullable();     // for display / recipient matching
            $table->string('invitee_public_key', 64)->nullable(); // Ed25519 hex the blob is sealed to (sealed mode)
            $table->text('sealed_secret_json')->nullable();  // {v,epkB64,ivB64,ctB64}; null for link mode
            $table->string('token_hash', 64)->unique();      // sha256 of the one-time token
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'accepted_at', 'revoked_at']);
            $table->index('invitee_public_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invites');
    }
};
