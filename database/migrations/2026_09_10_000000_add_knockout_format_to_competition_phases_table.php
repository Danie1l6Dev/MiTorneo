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
            // Null for a league phase (the format concept doesn't apply);
            // required for any knockout-style phase (Knockout/Semifinal/
            // Final), reusing the same ScheduleFormat values a league
            // schedule already uses (single_round = single match per cross,
            // home_and_away = two legs per cross).
            $table->string('knockout_format')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('competition_phases', function (Blueprint $table) {
            $table->dropColumn('knockout_format');
        });
    }
};
