<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The result of a "sorteo": the last tie-break when teams are still level
     * after points, goal difference, goals for, head-to-head and fair play.
     * One row per team drawn, sharing a `draw_group` (one id per draw), with
     * the `position` each got (1 = first). A table is recalculated every time
     * it is opened, so the draw has to be stored for it to stay put.
     */
    public function up(): void
    {
        Schema::create('tiebreak_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_phase_id')->constrained('competition_phases')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('draw_group');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index(['competition_phase_id', 'draw_group']);
            $table->unique(['competition_phase_id', 'team_id', 'draw_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiebreak_draws');
    }
};
