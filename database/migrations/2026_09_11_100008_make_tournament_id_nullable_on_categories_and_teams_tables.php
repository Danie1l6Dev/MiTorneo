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
        // T01-01/T01-05 -- same reasoning as groups.tournament_id
        // (2026_09_11_100007): the backfill command creates NEW global
        // Category/Team rows (tournament_id = NULL) rather than repointing
        // any existing per-tournament row. tournament_id on the OLD rows is
        // untouched here and dropped only in the later "Contraer" migration.
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('tournament_id')->nullable()->change();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('tournament_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('tournament_id')->nullable(false)->change();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('tournament_id')->nullable(false)->change();
        });
    }
};
