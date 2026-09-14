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
        // T01-05 -- "Expandir" step (T01-14): a team becomes a club's roster
        // for one category(+group), instead of belonging to a tournament
        // directly. club_id is nullable until the backfill command
        // (T01-15/T01-16) creates/matches the global Club rows.
        // tournament_id is kept for now, dropped only in T01-21.
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('club_id')->nullable()->after('tournament_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('club_id');
        });
    }
};
