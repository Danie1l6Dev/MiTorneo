<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Models\Category;
use App\Models\Club;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Two safeguards around the players' history:
 *  - nothing in app/ changes a player's planteles except PlayerRosterService
 *    (otherwise the history silently drifts from the roster);
 *  - a plantel with real records (goals, cards, sanctions) can't be deleted,
 *    because deleting it would delete its players and those records with them.
 */
class PlayerRosterHistoryGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_outside_the_roster_service_attaches_or_detaches_a_players_planteles(): void
    {
        $offenders = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if ($file->getFilename() === 'PlayerRosterService.php') {
                continue;
            }

            $contents = $file->getContents();

            // "$player->teams()->attach(...)", "$existingPlayer->teams()->detach()", ...
            if (preg_match_all('/\$\w*[pP]layer\w*->teams\(\)->(attach|detach|sync|syncWithoutDetaching|toggle)\(/', $contents, $matches)) {
                $offenders[] = $file->getRelativePathname().' ('.implode(', ', array_unique($matches[1])).')';
            }
        }

        $this->assertSame([], $offenders, 'These change a players planteles directly and would leave the history behind; go through PlayerRosterService instead.');
    }

    /**
     * @return array{user: User, team: Team, player: Player, match: TournamentMatch}
     */
    private function teamWithAPlayer(): array
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $club = Club::factory()->for($user)->create();
        $team = Team::factory()->for($tournament)->for($category)->create(['club_id' => $club->id]);
        $rival = Team::factory()->for($tournament)->for($category)->create();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $match = TournamentMatch::factory()->for($phase)->create(['home_team_id' => $team->id, 'away_team_id' => $rival->id]);

        return compact('user', 'team', 'player', 'match');
    }

    public function test_a_plantel_whose_player_has_a_goal_cannot_be_deleted(): void
    {
        ['user' => $user, 'team' => $team, 'player' => $player, 'match' => $match] = $this->teamWithAPlayer();
        MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $team->id, 'player_id' => $player->id, 'type' => MatchEventType::Goal]);

        $this->actingAs($user)
            ->delete(route('teams.destroy', $team))
            ->assertSessionHas('error');

        $this->assertModelExists($team);
        $this->assertModelExists($player);
        $this->assertSame(1, MatchEvent::query()->count());
    }

    public function test_a_plantel_with_a_sanction_cannot_be_deleted(): void
    {
        ['user' => $user, 'team' => $team, 'player' => $player, 'match' => $match] = $this->teamWithAPlayer();
        $event = MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $team->id, 'player_id' => $player->id, 'type' => MatchEventType::RedCard]);
        Sanction::factory()->create(['match_id' => $match->id, 'match_event_id' => $event->id, 'team_id' => $team->id, 'player_id' => $player->id]);

        $this->actingAs($user)->delete(route('teams.destroy', $team))->assertSessionHas('error');

        $this->assertModelExists($team);
    }

    public function test_a_plantel_is_protected_by_the_records_a_primary_player_has_under_another_plantel(): void
    {
        ['user' => $user, 'team' => $team, 'player' => $player, 'match' => $match] = $this->teamWithAPlayer();
        $otherTeam = Team::factory()->create(['category_id' => $team->category_id, 'tournament_id' => $team->tournament_id]);
        // The player's goal was logged for ANOTHER plantel: deleting this one would still delete him and it.
        MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $otherTeam->id, 'player_id' => $player->id, 'type' => MatchEventType::Goal]);

        $this->actingAs($user)->delete(route('teams.destroy', $team))->assertSessionHas('error');

        $this->assertModelExists($team);
        $this->assertModelExists($player);
    }

    public function test_a_plantel_without_records_can_still_be_deleted(): void
    {
        ['user' => $user, 'team' => $team] = $this->teamWithAPlayer();

        $this->actingAs($user)->delete(route('teams.destroy', $team))->assertSessionMissing('error');

        $this->assertModelMissing($team);
    }
}
