<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company sharing (docs/COMPANY_SHARING.md, C1): a second person - typically
 * the accountant - may work in someone else's company. The owner stays
 * `companies.user_id`; members are additive and carry a role. Content never
 * lives here - this row only authorizes the server bridges (numbering, PDF,
 * e-faktura, exports) for the member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16); // owner | accountant | member
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_members');
    }
};
