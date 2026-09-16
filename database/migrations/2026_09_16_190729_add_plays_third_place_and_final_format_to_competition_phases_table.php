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
            // Opt-in, only meaningful for a knockout-style phase with at
            // least 4 qualifiers -- see PhaseEligibilityService::canPlayThirdPlace().
            // Always false for a league phase.
            $table->boolean('plays_third_place')->default(false)->after('knockout_format');
            // Null = the final follows the phase's general knockout_format,
            // same as every other cross (today's behavior, unchanged). Set =
            // overrides the format for the final cross only, independent of
            // the rest of the bracket.
            $table->string('final_knockout_format')->nullable()->after('plays_third_place');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('competition_phases', function (Blueprint $table) {
            $table->dropColumn(['plays_third_place', 'final_knockout_format']);
        });
    }
};
