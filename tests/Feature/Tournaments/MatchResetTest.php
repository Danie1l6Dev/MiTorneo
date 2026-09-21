<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\SanctionType;
use App\Models\Category;
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
 * "Resetear partido" (TournamentMatchController::reset): undoes a
 * registered result and every match_event it has, so an organizer who
 * fat-fingered a whole match can redo it from scratch instead of deleting
 * events one at a time and editing scores back to empty by hand. Also
 * covers destroy()'s matching protection against orphaning a
 * committee-resolved sanction, since sanctions.match_id cascades on delete.
 */
class MatchResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: TournamentMatch, 1: Team, 2: Team}
     */
    private function makeFinishedMatch(User $user, array $overrides = []): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create(array_merge([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Finished,
            'home_score' => 2,
            'away_score' => 1,
        ], $overrides));

        return [$match, $home, $away];
    }

    public function test_a_user_can_reset_a_finished_match(): void
    {
        $user = User::factory()->create();
        [$match, $home] = $this->makeFinishedMatch($user);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($user)->patch(route('matches.reset', $match))
            ->assertRedirect(route('matches.edit', $match));

        $match->refresh();
        $this->assertSame(MatchStatus::Scheduled, $match->status);
        $this->assertNull($match->home_score);
        $this->assertNull($match->away_score);
        $this->assertSame(0, MatchEvent::query()->where('match_id', $match->id)->count());
    }

    public function test_resetting_clears_extra_time_and_penalty_scores_too(): void
    {
        $user = User::factory()->create();
        [$match] = $this->makeFinishedMatch($user, [
            'home_extra_time_score' => 1,
            'away_extra_time_score' => 1,
            'home_penalty_score' => 5,
            'away_penalty_score' => 4,
        ]);

        $this->actingAs($user)->patch(route('matches.reset', $match));

        $match->refresh();
        $this->assertNull($match->home_extra_time_score);
        $this->assertNull($match->away_extra_time_score);
        $this->assertNull($match->home_penalty_score);
        $this->assertNull($match->away_penalty_score);
    }

    public function test_resetting_removes_an_auto_manageable_double_yellow_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $home] = $this->makeFinishedMatch($user);
        $player = Player::factory()->for($home)->create();

        $yellowOne = MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $home->id, 'player_id' => $player->id, 'type' => MatchEventType::YellowCard]);
        MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $home->id, 'player_id' => $player->id, 'type' => MatchEventType::YellowCard]);

        Sanction::factory()->create([
            'match_id' => $match->id,
            'match_event_id' => $yellowOne->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => SanctionType::DoubleYellow,
        ]);

        $this->actingAs($user)->patch(route('matches.reset', $match))
            ->assertRedirect(route('matches.edit', $match));

        $this->assertSame(0, Sanction::query()->where('match_id', $match->id)->count());
        $this->assertSame(MatchStatus::Scheduled, $match->fresh()->status);
    }

    public function test_resetting_is_blocked_when_it_would_orphan_a_committee_resolved_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $home] = $this->makeFinishedMatch($user);
        $player = Player::factory()->for($home)->create();

        $redCardEvent = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        $sanction = Sanction::factory()->resolved(3)->create([
            'match_id' => $match->id,
            'match_event_id' => $redCardEvent->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($user)->patch(route('matches.reset', $match))
            ->assertRedirect(route('matches.edit', $match))
            ->assertSessionHas('error');

        $match->refresh();
        $this->assertSame(MatchStatus::Finished, $match->status);
        $this->assertNotNull($match->home_score);
        $this->assertDatabaseHas('match_events', ['id' => $redCardEvent->id]);
        $this->assertDatabaseHas('sanctions', ['id' => $sanction->id]);
    }

    public function test_deleting_a_match_is_blocked_when_it_would_cascade_delete_a_committee_resolved_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $home] = $this->makeFinishedMatch($user);
        $player = Player::factory()->for($home)->create();

        $redCardEvent = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        $sanction = Sanction::factory()->resolved(3)->create([
            'match_id' => $match->id,
            'match_event_id' => $redCardEvent->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($user)->delete(route('matches.destroy', $match))
            ->assertRedirect(route('matches.edit', $match))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('matches', ['id' => $match->id]);
        $this->assertDatabaseHas('sanctions', ['id' => $sanction->id]);
    }

    public function test_deleting_a_match_with_only_an_auto_manageable_sanction_still_works(): void
    {
        $user = User::factory()->create();
        [$match, $home] = $this->makeFinishedMatch($user);
        $player = Player::factory()->for($home)->create();

        $yellowOne = MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $home->id, 'player_id' => $player->id, 'type' => MatchEventType::YellowCard]);
        MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $home->id, 'player_id' => $player->id, 'type' => MatchEventType::YellowCard]);

        Sanction::factory()->create([
            'match_id' => $match->id,
            'match_event_id' => $yellowOne->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => SanctionType::DoubleYellow,
        ]);

        $phase = $match->competitionPhase;

        $this->actingAs($user)->delete(route('matches.destroy', $match))
            ->assertRedirect(route('phases.show', $phase));

        $this->assertDatabaseMissing('matches', ['id' => $match->id]);
    }

    public function test_a_user_cannot_reset_another_users_match(): void
    {
        $owner = User::factory()->create();
        [$match] = $this->makeFinishedMatch($owner);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->patch(route('matches.reset', $match))->assertForbidden();

        $this->assertSame(MatchStatus::Finished, $match->fresh()->status);
    }

    // ── Botón "Resetear partido" / "Volver al calendario" ──────────────────

    public function test_the_reset_button_only_shows_once_a_result_is_registered(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $scheduledMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->actingAs($user)->get(route('matches.edit', $scheduledMatch))
            ->assertOk()
            ->assertDontSee('Resetear partido');

        [$finishedMatch] = $this->makeFinishedMatch($user);

        $this->actingAs($user)->get(route('matches.edit', $finishedMatch))
            ->assertOk()
            ->assertSee('Resetear partido');
    }

    public function test_the_match_edit_page_links_back_to_the_calendar(): void
    {
        $user = User::factory()->create();
        [$match] = $this->makeFinishedMatch($user);

        $this->actingAs($user)->get(route('matches.edit', $match))
            ->assertOk()
            ->assertSee('Volver al calendario')
            ->assertSee(route('phases.show', $match->competitionPhase).'#calendario', false);
    }
}
