<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Plan: docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
        // T01-08 -- nullable on purpose: players already loaded before this
        // change have no birth date. A player with no birth_date keeps only
        // the category the backfill already inferred for them (T01-17) and
        // cannot be added to any new category (T01-11) until this is filled
        // in -- see the "dato incompleto" banner in the player UI (T01-27).
        Schema::table('players', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('full_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
