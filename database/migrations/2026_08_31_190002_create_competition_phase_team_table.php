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
        // Records which teams participate in a given phase, independent of the
        // category-wide group they may belong to. Populated when a phase is
        // created from a qualification/draw (e.g. "top 2 of each group" or "the
        // winners of the quarterfinals"): that phase's standings/schedule are
        // then computed from exactly this roster instead of the whole category
        // or a category-level group. A phase with no rows here (the common case
        // today, e.g. the first league phase of a category) falls back to the
        // category's own teams/groups as before.
        Schema::create('competition_phase_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_phase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['competition_phase_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competition_phase_team');
    }
};
