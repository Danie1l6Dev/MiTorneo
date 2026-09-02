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
        Schema::table('competition_phases', function (Blueprint $table) {
            // Set when a single-table league phase is closed out by directly
            // declaring its top team champion instead of spawning a further
            // phase (a knockout round or another league) -- the alternative,
            // terminal outcome to "advance to a next phase".
            $table->foreignId('champion_team_id')->nullable()->after('order')->constrained('teams')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('competition_phases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('champion_team_id');
        });
    }
};
