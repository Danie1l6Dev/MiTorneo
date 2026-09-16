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
        Schema::table('matches', function (Blueprint $table) {
            // Flags the extra 3er/4to puesto cross a knockout bracket can
            // opt into (CompetitionPhase::$plays_third_place) -- always a
            // single match, its two sides wired as "loser of" each
            // semifinal cross. Shares its round_number with the final but
            // must never be counted as a second cross of that round (see
            // PhaseBoardService::bracketRounds()), hence a dedicated flag
            // rather than inferring it from round_number/cross count.
            $table->boolean('is_third_place')->default(false)->after('first_leg_match_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('is_third_place');
        });
    }
};
