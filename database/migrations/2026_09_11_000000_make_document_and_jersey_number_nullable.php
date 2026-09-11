<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documento (players/coaches) and dorsal (players) become optional --
     * per-record, not per-team: the app-level "unique among active
     * teammates" rule in PlayerRequest/the reactivation check already skips
     * a null value entirely (Laravel's own `nullable` validation rule stops
     * at a null value before the `unique` rule ever runs), so leaving one
     * blank never blocks or gets blocked by another blank one on the same
     * team. Existing rows are untouched -- they already all have a value,
     * this only lifts the requirement going forward.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('document_number')->nullable()->change();
            $table->unsignedSmallInteger('jersey_number')->nullable()->change();
        });

        Schema::table('coaches', function (Blueprint $table) {
            $table->string('document_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('document_number')->nullable(false)->change();
            $table->unsignedSmallInteger('jersey_number')->nullable(false)->change();
        });

        Schema::table('coaches', function (Blueprint $table) {
            $table->string('document_number')->nullable(false)->change();
        });
    }
};
