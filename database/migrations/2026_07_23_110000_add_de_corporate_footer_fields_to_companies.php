<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // DE Geschaeftsbrief corporate data - mandatory on GmbH/UG
            // invoice footers (Registergericht, Handelsregisternummer,
            // Geschaeftsfuehrer, chair of the supervisory board).
            $table->string('register_court', 128)->nullable();
            $table->string('register_number', 64)->nullable();
            $table->string('managing_directors', 512)->nullable();
            $table->string('supervisory_board_chair', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'register_court',
                'register_number',
                'managing_directors',
                'supervisory_board_chair',
            ]);
        });
    }
};
