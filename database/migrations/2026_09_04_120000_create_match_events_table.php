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
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            // Exactly one of these two is ever set -- a card can belong to a
            // player OR to the team's coach, never both, enforced at the
            // Form Request level (same "nullable columns, app-level XOR"
            // pattern MatchParticipant already uses in this codebase rather
            // than a generic polymorphic relation). Goals/assists are always
            // player_id: a coach is never the one scoring.
            $table->foreignId('player_id')->nullable()->constrained('players')->cascadeOnDelete();
            $table->foreignId('coach_id')->nullable()->constrained('coaches')->cascadeOnDelete();
            $table->string('type');
            // Nullable: minute tracking isn't used yet -- events are recorded
            // purely to count toward stats (goals/assists/cards), not tied to
            // a moment in the match. Plain integer (1, 12, 45, 90...) rather
            // than stoppage-time notation (45+2) whenever it IS used later.
            $table->unsignedSmallInteger('minute')->nullable();
            $table->timestamps();

            $table->index(['match_id', 'type']);
            $table->index(['player_id', 'type']);
            $table->index(['coach_id', 'type']);
            $table->index(['team_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};
