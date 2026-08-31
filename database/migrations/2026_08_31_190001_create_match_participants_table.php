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
        Schema::create('match_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('side');
            $table->string('type');

            // Populated when type = team: the participant is a fixed, known team.
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();

            // Populated when type = match_winner: the participant is whoever wins this other match.
            $table->foreignId('source_match_id')->nullable()->constrained('matches')->cascadeOnDelete();

            // Populated when type = standing_position: which phase's standings table to read,
            // optionally scoped to one group of it (null group = the whole category's table),
            // and which finishing position within that table.
            $table->foreignId('source_phase_id')->nullable()->constrained('competition_phases')->cascadeOnDelete();
            $table->foreignId('source_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->unsignedSmallInteger('position')->nullable();

            $table->timestamps();

            $table->unique(['match_id', 'side']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_participants');
    }
};
