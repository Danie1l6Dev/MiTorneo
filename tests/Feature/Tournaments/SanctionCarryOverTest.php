<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Enums\SanctionType;
use App\Models\Category;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A player expelled with more fechas than their current tournament has
 * matches left (e.g. a straight red in a semifinal, resolved with 7 fechas)
 * must keep owing the rest once that tournament ends -- into whatever team
 * they next play for, even a different age category's team in a later
 * tournament, because the suspension belongs to the PLAYER, not to the team
 * they happened to be on when the card was shown. See
 * Sanction::subjectTeamIds() and Sanction::teamMatchSequence().
 */
class SanctionCarryOverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Team, 1: Team, 2: TournamentMatch, 3: Player}
     */
    private function makeOriginTournament(User $user): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'round_number' => 1,
        ]);

        $player = Player::factory()->for($home)->create(['jersey_number' => 9]);

        return [$home, $away, $match, $player];
    }

    /**
     * Same phase, same two teams, a later round_number -- what
     * Sanction::teamMatchSequence() uses to order "the following matches" a
     * sanction's fechas actually get served in, mirroring
     * SanctionManagementTest's own helper of the same name.
     */
    private function makeFollowUpMatch(TournamentMatch $originMatch, int $roundNumber, MatchStatus $status = MatchStatus::Scheduled): TournamentMatch
    {
        return TournamentMatch::factory()->for($originMatch->competitionPhase)->create([
            'tournament_id' => $originMatch->tournament_id,
            'category_id' => $originMatch->category_id,
            'home_team_id' => $originMatch->home_team_id,
            'away_team_id' => $originMatch->away_team_id,
            'round_number' => $roundNumber,
            'status' => $status,
        ]);
    }

    /**
     * A brand-new tournament (created strictly after the origin one, so it
     * sorts after it in Sanction::teamMatchSequence()), with its own
     * category/team -- what an older age category the player is promoted
     * into next season actually looks like: a different Team row entirely.
     *
     * @return array{0: Team, 1: TournamentMatch}
     */
    private function makeNextSeasonTeamAndMatch(User $user, MatchStatus $status = MatchStatus::Scheduled): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $team = Team::factory()->for($tournament)->for($category)->create();
        $opponent = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'round_number' => 1,
            'status' => $status,
        ]);

        return [$team, $match];
    }

    public function test_unserved_fechas_carry_over_into_the_players_new_team_after_a_category_promotion(): void
    {
        $user = User::factory()->create();
        [$home, $away, $originMatch, $player] = $this->makeOriginTournament($user);

        // Only ONE more match is ever scheduled in the origin tournament --
        // not enough to serve all 3 fechas the committee assigned.
        $this->makeFollowUpMatch($originMatch, 2, MatchStatus::Finished);

        $sanction = Sanction::factory()->resolved(3)->create([
            'match_id' => $originMatch->id,
            'match_event_id' => MatchEvent::factory()->create([
                'match_id' => $originMatch->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card',
            ]),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        // Only 1 of 3 fechas served, and the origin tournament has no more
        // matches for this team -- still active, still owing 2 fechas.
        $this->assertSame(1, $sanction->matchesServedCount());
        $this->assertTrue($sanction->isActive());
        $this->assertFalse($sanction->isFulfilled());
        $this->assertTrue($player->fresh()->isSuspended());

        // The player ages up into a new category's team, in a brand-new
        // tournament -- exactly what happens via the club roster's "add to
        // another plantel" flow, never a manual sanctions step.
        [$newTeam, $newMatch] = $this->makeNextSeasonTeamAndMatch($user);
        $player->teams()->attach($newTeam->id);

        $newMatch->update(['status' => MatchStatus::Finished, 'home_score' => 1, 'away_score' => 0]);

        $sanction = $sanction->fresh();
        $this->assertSame(2, $sanction->matchesServedCount());
        $this->assertTrue($sanction->isActive());
        $this->assertTrue($player->fresh()->isSuspended());

        $secondNewMatch = $this->makeFollowUpMatch($newMatch, 2, MatchStatus::Finished);

        $sanction = $sanction->fresh();
        $this->assertSame(3, $sanction->matchesServedCount());
        $this->assertTrue($sanction->isFulfilled());
        $this->assertFalse($player->fresh()->isSuspended());
        $this->assertTrue($secondNewMatch->exists);
    }

    public function test_a_still_pending_suspension_blocks_the_player_in_their_new_teams_matches_too(): void
    {
        $user = User::factory()->create();
        [, , $originMatch, $player] = $this->makeOriginTournament($user);

        Sanction::factory()->create([
            'match_id' => $originMatch->id,
            'match_event_id' => MatchEvent::factory()->create([
                'match_id' => $originMatch->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card',
            ]),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        [$newTeam, $newMatch] = $this->makeNextSeasonTeamAndMatch($user);
        $player->teams()->attach($newTeam->id);

        $sanction = Sanction::query()->where('player_id', $player->id)->firstOrFail();
        $this->assertTrue($sanction->blocksMatch($newMatch->id));

        $this->actingAs($user)->post(route('matches.events.store', $newMatch), [
            'type' => 'goal',
            'player_id' => $player->id,
        ])->assertSessionHasErrors('player_id');
    }

    /**
     * Coach has no multi-team roster the way Player does (one Coach row per
     * team, never shared -- see Coach's own docblock), so a coach's
     * suspension only ever tracks their one origin team. Confirms that
     * limitation stays exactly as it was, not a regression from this
     * change.
     */
    public function test_a_coachs_suspension_is_not_affected_by_a_different_teams_matches(): void
    {
        $user = User::factory()->create();
        [$home, , $originMatch] = $this->makeOriginTournament($user);
        $coach = Coach::factory()->for($home)->create();

        $sanction = Sanction::factory()->resolved(1)->create([
            'match_id' => $originMatch->id,
            'match_event_id' => MatchEvent::factory()->create([
                'match_id' => $originMatch->id, 'team_id' => $home->id, 'coach_id' => $coach->id, 'player_id' => null, 'type' => 'red_card',
            ]),
            'team_id' => $home->id,
            'player_id' => null,
            'coach_id' => $coach->id,
            'type' => SanctionType::RedCard,
        ]);

        // An unrelated team's finished match must never count toward this
        // coach's fecha.
        $this->makeNextSeasonTeamAndMatch($user, MatchStatus::Finished);

        $this->assertSame([$home->id], $sanction->subjectTeamIds());
        $this->assertSame(0, $sanction->matchesServedCount());
    }
}
