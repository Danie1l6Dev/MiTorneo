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
        // Set by TeamExpulsionService when a team's expulsion forces one of
        // its still-unplayed matches to a 0-3 loss -- kept separate from a
        // genuine 0-3 scoreline so the calendar/bracket can label it
        // "Perdido por W" instead of a normal result. walkover_team_id is
        // the team that lost by forfeit (always one of home_team_id/
        // away_team_id), denormalized so the losing side is still known even
        // if the team is later removed from the category.
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('is_walkover')->default(false)->after('status');
            $table->foreignId('walkover_team_id')->nullable()->after('is_walkover')->constrained('teams')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('walkover_team_id');
            $table->dropColumn('is_walkover');
        });
    }
};
