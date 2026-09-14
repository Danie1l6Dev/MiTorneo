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
        // T01-01/T01-02 -- "Expandir" step (T01-14): categories become a
        // per-organizer global catalog instead of belonging to a single
        // tournament. user_id and the birth-year range are added nullable on
        // purpose -- existing rows have neither until the backfill command
        // (T01-15/T01-16) populates them. tournament_id is deliberately kept
        // for now (dropped only in the later "Contraer" migration, T01-21) so
        // the app keeps working unchanged until the cutover (T01-20).
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('birth_year_from')->nullable()->after('description');
            $table->unsignedSmallInteger('birth_year_to')->nullable()->after('birth_year_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['birth_year_from', 'birth_year_to']);
        });
    }
};
