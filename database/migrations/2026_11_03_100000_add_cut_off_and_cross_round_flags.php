<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `matches.cut_off` marks a match that was left unplayed on purpose when
     * the organizer cut a league short ("recorte") to move on to the next
     * phase, so it can be told apart from one cancelled for another reason
     * (e.g. a team's expulsion) and the cut can be undone.
     *
     * `competition_phases.cross_round` marks a knockout-type phase made of a
     * single round of crosses (no pre-wired later rounds): its winners (plus
     * the teams that passed directly and the best losers) feed a further phase
     * that is drawn again, instead of a fixed bracket.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('cut_off')->default(false)->after('is_third_place');
        });

        Schema::table('competition_phases', function (Blueprint $table) {
            $table->boolean('cross_round')->default(false)->after('plays_third_place');
        });
    }

    public function down(): void
    {
        Schema::table('competition_phases', function (Blueprint $table) {
            $table->dropColumn('cross_round');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('cut_off');
        });
    }
};
