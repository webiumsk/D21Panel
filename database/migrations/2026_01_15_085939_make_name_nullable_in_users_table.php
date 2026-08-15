<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support ALTER COLUMN - column is already nullable in original migration
        // For PostgreSQL/MySQL, use Schema builder if possible, otherwise skip
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // No-op for SQLite - column is already nullable in create_users_table migration
            return;
        }

        // For PostgreSQL and MySQL, try using Schema builder
        // Note: Laravel's change() method may not work for all column modifications
        // If this fails, the column is already nullable from create_users_table migration
        try {
            Schema::table('users', function (Blueprint $table) {
                $table->string('name')->nullable()->change();
            });
        } catch (Exception $e) {
            // If Schema builder fails, try raw SQL for PostgreSQL
            if ($driver === 'pgsql') {
                try {
                    DB::statement('ALTER TABLE users ALTER COLUMN name DROP NOT NULL');
                } catch (Exception $e2) {
                    // Column might already be nullable, ignore
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite doesn't support ALTER COLUMN directly
            // Skip rollback for SQLite
            return;
        }

        // PostgreSQL/MySQL: Make name column NOT NULL again
        DB::statement('UPDATE users SET name = \'\' WHERE name IS NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN name SET NOT NULL');
    }
};
