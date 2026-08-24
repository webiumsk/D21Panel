<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // wallet_type is already a plain nullable string since the cashu migration.
            return;
        }

        if ($driver === 'pgsql') {
            $enumType = DB::selectOne("
                SELECT t.typname
                FROM pg_type t
                JOIN pg_attribute a ON a.atttypid = t.oid
                JOIN pg_class c ON c.oid = a.attrelid
                WHERE c.relname = 'stores'
                  AND a.attname = 'wallet_type'
                  AND t.typtype = 'e'
                  AND a.attnum > 0
                  AND NOT a.attisdropped
            ");

            if ($enumType) {
                $type = '"'.str_replace('"', '""', $enumType->typname).'"';
                DB::statement("ALTER TYPE {$type} ADD VALUE IF NOT EXISTS 'blitz'");

                return;
            }

            DB::statement('ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_wallet_type_check');
            DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_wallet_type_check CHECK (
                wallet_type IS NULL OR (wallet_type::text = ANY (ARRAY['blink'::text, 'aqua_boltz'::text, 'nwc'::text, 'cashu'::text, 'blitz'::text]))
            )");

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Also repairs the mysql enum that was missing 'nwc' since the nwc rollout.
            DB::statement("ALTER TABLE `stores` MODIFY COLUMN `wallet_type` ENUM('blink', 'aqua_boltz', 'nwc', 'cashu', 'blitz') NULL");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Refuse to roll back over live Blitz data - silently remapping blitz -> blink
        // would change store behavior. Migrate the stores off Blitz first.
        $blitzStores = DB::table('stores')->where('wallet_type', 'blitz')->count();
        $blitzConnections = DB::table('wallet_connections')->where('type', 'blitz')->count();
        if ($blitzStores > 0 || $blitzConnections > 0) {
            throw new RuntimeException(
                "Cannot roll back the blitz wallet_type migration: {$blitzStores} store(s) and "
                ."{$blitzConnections} wallet connection(s) still use Blitz. Reassign them first."
            );
        }

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            $enumType = DB::selectOne("
                SELECT t.typname
                FROM pg_type t
                JOIN pg_attribute a ON a.atttypid = t.oid
                JOIN pg_class c ON c.oid = a.attrelid
                WHERE c.relname = 'stores'
                  AND a.attname = 'wallet_type'
                  AND t.typtype = 'e'
                  AND a.attnum > 0
                  AND NOT a.attisdropped
            ");

            if (! $enumType) {
                DB::statement('ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_wallet_type_check');
                DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_wallet_type_check CHECK (
                    wallet_type IS NULL OR (wallet_type::text = ANY (ARRAY['blink'::text, 'aqua_boltz'::text, 'nwc'::text, 'cashu'::text]))
                )");
            }
            // Native enum values cannot be removed in place; leaving 'blitz' in the type is harmless.

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `stores` MODIFY COLUMN `wallet_type` ENUM('blink', 'aqua_boltz', 'nwc', 'cashu') NULL");
        }
    }
};
