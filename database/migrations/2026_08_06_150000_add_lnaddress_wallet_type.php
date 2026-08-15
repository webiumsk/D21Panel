<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Universal LN address wallet type (LUD-21 verify wallets: Blitz, Flash,
     * Coinos, ...). Widens stores.wallet_type AND wallet_connections.type in
     * one migration - the constraints must always move in pairs (the blitz/flash
     * rollout missed the second one and broke saving connections on live).
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // Both columns are plain strings on sqlite - nothing to widen.
            return;
        }

        if ($driver === 'pgsql') {
            $enumType = $this->storesWalletTypeEnum();

            if ($enumType) {
                $type = '"'.str_replace('"', '""', $enumType->typname).'"';
                DB::statement("ALTER TYPE {$type} ADD VALUE IF NOT EXISTS 'lnaddress'");
            } else {
                DB::statement('ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_wallet_type_check');
                DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_wallet_type_check CHECK (
                    wallet_type IS NULL OR (wallet_type::text = ANY (ARRAY['blink'::text, 'aqua_boltz'::text, 'nwc'::text, 'cashu'::text, 'blitz'::text, 'flash'::text, 'lnaddress'::text]))
                )");
            }

            DB::statement('ALTER TABLE wallet_connections DROP CONSTRAINT IF EXISTS wallet_connections_type_check');
            DB::statement("ALTER TABLE wallet_connections ADD CONSTRAINT wallet_connections_type_check CHECK (
                type::text = ANY (ARRAY['blink'::text, 'aqua_descriptor'::text, 'nwc'::text, 'blitz'::text, 'flash'::text, 'lnaddress'::text])
            )");

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `stores` MODIFY COLUMN `wallet_type` ENUM('blink', 'aqua_boltz', 'nwc', 'cashu', 'blitz', 'flash', 'lnaddress') NULL");
            DB::statement("ALTER TABLE `wallet_connections` MODIFY COLUMN `type` ENUM('blink', 'aqua_descriptor', 'nwc', 'blitz', 'flash', 'lnaddress') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Refuse to roll back over live lnaddress data - silently remapping would
        // change store behavior. Migrate the stores off lnaddress first.
        $stores = DB::table('stores')->where('wallet_type', 'lnaddress')->count();
        $connections = DB::table('wallet_connections')->where('type', 'lnaddress')->count();
        if ($stores > 0 || $connections > 0) {
            throw new RuntimeException(
                "Cannot roll back the lnaddress wallet_type migration: {$stores} store(s) and "
                ."{$connections} wallet connection(s) still use lnaddress. Reassign them first."
            );
        }

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            if (! $this->storesWalletTypeEnum()) {
                DB::statement('ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_wallet_type_check');
                DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_wallet_type_check CHECK (
                    wallet_type IS NULL OR (wallet_type::text = ANY (ARRAY['blink'::text, 'aqua_boltz'::text, 'nwc'::text, 'cashu'::text, 'blitz'::text, 'flash'::text]))
                )");
            }
            // Native enum values cannot be removed in place; leaving 'lnaddress' in the type is harmless.

            DB::statement('ALTER TABLE wallet_connections DROP CONSTRAINT IF EXISTS wallet_connections_type_check');
            DB::statement("ALTER TABLE wallet_connections ADD CONSTRAINT wallet_connections_type_check CHECK (
                type::text = ANY (ARRAY['blink'::text, 'aqua_descriptor'::text, 'nwc'::text, 'blitz'::text, 'flash'::text])
            )");

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `stores` MODIFY COLUMN `wallet_type` ENUM('blink', 'aqua_boltz', 'nwc', 'cashu', 'blitz', 'flash') NULL");
            DB::statement("ALTER TABLE `wallet_connections` MODIFY COLUMN `type` ENUM('blink', 'aqua_descriptor', 'nwc', 'blitz', 'flash') NOT NULL");
        }
    }

    private function storesWalletTypeEnum(): ?object
    {
        return DB::selectOne("
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
    }
};
