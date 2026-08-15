<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efaktura_cpds_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 128);
            $table->string('base_url', 255);
            // CPDS-specific sent-document detail path (with {id} placeholder);
            // overrides the global EFAKTURA_SAPI_SEND_DETAIL_PATH per provider.
            $table->string('send_detail_path', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efaktura_cpds_providers');
    }
};
