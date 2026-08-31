<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchParticipantSide;
use App\Enums\MatchParticipantSourceType;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\MatchParticipant;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * These tests exercise the schema/model groundwork for chained phases
 * (league -> knockout -> final), not any automatic resolution logic: creating
 * matches without teams yet, and describing where each side's team will come
 * from (a fixed team, the winner of another match, or a standings position).
 * Nothing here resolves those references into concrete teams.
 */
class MatchParticipantArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function makePhase(): CompetitionPhase
    {
        return CompetitionPhase::factory()->create();
    }

    public function test_a_match_can_be_created_without_teams_assigned_yet(): void
    {
        $phase = $this->makePhase();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'home_team_id' => null,
            'away_team_id' => null,
        ]);

        $this->assertNull($match->fresh()->home_team_id);
        $this->assertNull($match->fresh()->away_team_id);
    }

    public function test_a_participant_can_reference_a_fixed_team(): void
    {
        $phase = $this->makePhase();
        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
        ]);
        $team = Team::factory()->for($phase->tournament)->for($phase->category)->create();

        $participant = MatchParticipant::factory()->for($match, 'match')->create([
            'side' => MatchParticipantSide::Home,
            'type' => MatchParticipantSourceType::Team,
            'team_id' => $team->id,
        ]);

        $this->assertTrue($match->homeParticipant->is($participant));
        $this->assertTrue($participant->team->is($team));
    }

    public function test_a_participant_can_reference_the_winner_of_another_match(): void
    {
        $phase = $this->makePhase();

        $sourceMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
        ]);

        $pendingMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'home_team_id' => null,
            'away_team_id' => null,
        ]);

        $participant = MatchParticipant::factory()
            ->for($pendingMatch, 'match')
            ->matchWinner($sourceMatch)
            ->create(['side' => MatchParticipantSide::Home]);

        $this->assertSame(MatchParticipantSourceType::MatchWinner, $participant->type);
        $this->assertTrue($participant->sourceMatch->is($sourceMatch));
        $this->assertNull($participant->team_id);
    }

    public function test_a_participant_can_reference_a_standing_position_within_a_group(): void
    {
        $phase = $this->makePhase();
        $group = Group::factory()->for($phase->tournament)->for($phase->category)->create();

        $pendingMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'home_team_id' => null,
            'away_team_id' => null,
        ]);

        $participant = MatchParticipant::factory()
            ->for($pendingMatch, 'match')
            ->standingPosition(1)
            ->create([
                'side' => MatchParticipantSide::Home,
                'source_phase_id' => $phase->id,
                'source_group_id' => $group->id,
            ]);

        $this->assertSame(MatchParticipantSourceType::StandingPosition, $participant->type);
        $this->assertTrue($participant->sourcePhase->is($phase));
        $this->assertTrue($participant->sourceGroup->is($group));
        $this->assertSame(1, $participant->position);
    }

    public function test_a_participant_can_reference_a_standing_position_for_a_whole_category_without_a_group(): void
    {
        $phase = $this->makePhase();

        $pendingMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'home_team_id' => null,
            'away_team_id' => null,
        ]);

        $participant = MatchParticipant::factory()
            ->for($pendingMatch, 'match')
            ->standingPosition(2)
            ->create([
                'side' => MatchParticipantSide::Away,
                'source_phase_id' => $phase->id,
            ]);

        $this->assertNull($participant->source_group_id);
        $this->assertSame(2, $participant->position);
    }

    public function test_home_and_away_participant_relations_resolve_to_the_correct_side(): void
    {
        $phase = $this->makePhase();
        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'home_team_id' => null,
            'away_team_id' => null,
        ]);

        $home = MatchParticipant::factory()->for($match, 'match')->create(['side' => MatchParticipantSide::Home]);
        $away = MatchParticipant::factory()->for($match, 'match')->create(['side' => MatchParticipantSide::Away]);

        $this->assertTrue($match->homeParticipant->is($home));
        $this->assertTrue($match->awayParticipant->is($away));
        $this->assertCount(2, $match->participants);
    }

    public function test_a_match_cannot_have_two_participant_descriptions_for_the_same_side(): void
    {
        $phase = $this->makePhase();
        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
        ]);

        MatchParticipant::factory()->for($match, 'match')->create(['side' => MatchParticipantSide::Home]);

        $this->expectException(QueryException::class);

        MatchParticipant::factory()->for($match, 'match')->create(['side' => MatchParticipantSide::Home]);
    }
}
