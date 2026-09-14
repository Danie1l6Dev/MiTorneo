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
        // T01-03 -- Group globalizes the same way Category does: the
        // backfill command creates new global Group rows (tournament_id =
        // NULL, category_id = the new global Category) rather than
        // repointing any existing per-tournament row (which would break the
        // legacy tournament-scoped listing before the app has cut over).
        // tournament_id on the OLD rows is untouched here and dropped only
        // in the later "Contraer" migration.
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedBigInteger('tournament_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedBigInteger('tournament_id')->nullable(false)->change();
        });
    }
};
