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
        // T01-09 -- a player can be rostered on more than one Team (one per
        // category/group they actually play in, e.g. the same kid on both
        // "Nilmar Cebollita" and "Nilmar Infantil (A)"). This replaces
        // players.team_id (a single FK, kept for now and dropped only in the
        // later "Contraer" migration once the app reads through this pivot
        // instead). jersey_number lives here (nullable, same as today)
        // because a player can have a different dorsal per squad; identity
        // fields (full_name, document_number, birth_date) stay on Player
        // itself so they're entered only once.
        Schema::create('player_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('jersey_number')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'team_id']);
            $table->index(['team_id', 'jersey_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_team');
    }
};
