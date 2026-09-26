<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per stay of a player on a plantel, with when it started and
     * ended and why -- the history the roster tables never kept (moving a
     * player to another club, promoting them or taking them off a plantel
     * just rewrote players.team_id / player_team).
     *
     * The club/plantel/category/group NAMES are copied into the row so the
     * history still reads right after a plantel is renamed or deleted (team_id
     * and club_id only null out, they never cascade the history away). Rows
     * built after the fact, from events and sanctions, carry is_estimated.
     *
     * Purely additive: nothing existing is touched.
     */
    public function up(): void
    {
        Schema::create('player_team_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->string('club_name')->nullable();
            $table->string('team_name');
            $table->string('category_name')->nullable();
            $table->string('group_name')->nullable();
            $table->unsignedSmallInteger('jersey_number')->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->string('start_reason');
            $table->string('end_reason')->nullable();
            $table->boolean('is_estimated')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'ended_on']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_team_history');
    }
};
