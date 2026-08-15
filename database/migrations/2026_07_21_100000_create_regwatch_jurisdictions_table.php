<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RegWatch (docs/LEGAL.md): jurisdictions Webium LLC and its clients
        // operate in. Rules and monitored sources hang off these rows.
        Schema::create('regwatch_jurisdictions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // ISO-like code; sub-national entries use a suffix (US-WY).
            $table->string('code', 8)->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regwatch_jurisdictions');
    }
};
