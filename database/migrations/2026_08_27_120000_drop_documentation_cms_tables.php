<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User documentation moved to docs-as-code (repo Markdown under docs/user),
 * so the database CMS tables are no longer used. Drop them. down() recreates
 * the original schema (data is not restored).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('documentation_articles');
        Schema::dropIfExists('documentation_categories');
    }

    public function down(): void
    {
        Schema::create('documentation_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('documentation_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->json('title');
            $table->json('content');
            $table->foreignUuid('category_id')->nullable()->constrained('documentation_categories')->onDelete('set null');
            $table->integer('order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->json('meta_description')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('category_id');
            $table->index('is_published');
            $table->index(['category_id', 'order']);
        });
    }
};
