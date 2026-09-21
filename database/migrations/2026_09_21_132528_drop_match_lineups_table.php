<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "convocatoria" (match-day call-up) step entirely -- the match
 * edit page's quick-add roster panel now shows Team::clubPlayersEligibleForLineup()
 * directly for each side instead of a subset picked here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('match_lineups');
    }

    public function down(): void
    {
        Schema::create('match_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['match_id', 'player_id']);
        });
    }
};
