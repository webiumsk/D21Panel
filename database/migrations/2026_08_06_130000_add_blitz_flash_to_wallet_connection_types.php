<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The blitz/flash rollouts widened stores.wallet_type but missed the
     * wallet_connections.type check constraint (pgsql) / enum (mysql), so
     * saving a Blitz or Flash connection failed with a check violation.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // Plain string since the nwc migration - nothing to widen.
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE wallet_connections DROP CONSTRAINT IF EXISTS wallet_connections_type_check');
            DB::statement("ALTER TABLE wallet_connections ADD CONSTRAINT wallet_connections_type_check CHECK (
                type::text = ANY (ARRAY['blink'::text, 'aqua_descriptor'::text, 'nwc'::text, 'blitz'::text, 'flash'::text])
            )");

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `wallet_connections` MODIFY COLUMN `type` ENUM('blink', 'aqua_descriptor', 'nwc', 'blitz', 'flash') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Refuse to roll back over live Blitz/Flash connections.
        $count = DB::table('wallet_connections')->whereIn('type', ['blitz', 'flash'])->count();
        if ($count > 0) {
            throw new RuntimeException(
                "Cannot roll back the wallet_connections type migration: {$count} connection(s) still use Blitz or Flash. Reassign them first."
            );
        }

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE wallet_connections DROP CONSTRAINT IF EXISTS wallet_connections_type_check');
            DB::statement("ALTER TABLE wallet_connections ADD CONSTRAINT wallet_connections_type_check CHECK (
                type::text = ANY (ARRAY['blink'::text, 'aqua_descriptor'::text, 'nwc'::text])
            )");

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `wallet_connections` MODIFY COLUMN `type` ENUM('blink', 'aqua_descriptor', 'nwc') NOT NULL");
        }
    }
};
