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
        Schema::create('sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            // The single card event (a yellow for double_yellow, a red for
            // red_card) that SanctionService last synced this row from --
            // see that service for why "which exact row" never has to be
            // ambiguous, and MatchEventController for how deleting it keeps
            // this in sync.
            $table->foreignId('match_event_id')->constrained('match_events')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            // Exactly one of these two is ever set -- same nullable-columns
            // app-level XOR pattern match_events already uses for player_id/
            // coach_id, enforced in SanctionService rather than a DB
            // constraint.
            $table->foreignId('player_id')->nullable()->constrained('players')->cascadeOnDelete();
            $table->foreignId('coach_id')->nullable()->constrained('coaches')->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('pending');
            // Null while pending -- a red card's duration is never assumed,
            // only ever set once the Comité Directivo resolves it (or,
            // for double_yellow, fixed at 1 by SanctionService itself).
            $table->unsignedSmallInteger('matches_banned')->nullable();
            // Only ever set for a coach sanction (enforced in
            // SanctionResolveRequest) -- a player is never fined.
            $table->decimal('fine_amount', 10, 2)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['match_id']);
            $table->index(['player_id', 'status']);
            $table->index(['coach_id', 'status']);
            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sanctions');
    }
};
