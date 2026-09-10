<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Enums\SanctionStatus;
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
use App\Services\SanctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctionManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: TournamentMatch, 1: Player, 2: Player}
     */
    private function makeMatchWithPlayers(User $user): array
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
        ]);

        $homePlayer = Player::factory()->for($home)->create(['jersey_number' => 9]);
        $awayPlayer = Player::factory()->for($away)->create(['jersey_number' => 7]);

        return [$match, $homePlayer, $awayPlayer];
    }

    /**
     * @return array{0: TournamentMatch, 1: Coach}
     */
    private function makeMatchWithCoach(User $user): array
    {
        [$match] = $this->makeMatchWithPlayers($user);

        $coach = Coach::factory()->for($match->homeTeam)->create();

        return [$match, $coach];
    }

    /**
     * Same phase, same two teams, a later round_number -- what
     * Sanction::teamMatchSequence() uses to order "the following matches" a
     * sanction's fechas actually get served in.
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

    // ── Regla 1: doble amarilla ─────────────────────────────────────────

    public function test_a_players_second_yellow_card_auto_generates_a_resolved_one_match_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'player_id' => $player->id,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseMissing('sanctions', ['player_id' => $player->id]);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'player_id' => $player->id,
        ])->assertRedirect(route('matches.edit', $match));

        $sanction = Sanction::query()->where('player_id', $player->id)->first();

        $this->assertNotNull($sanction);
        $this->assertSame(SanctionType::DoubleYellow, $sanction->type);
        $this->assertSame(SanctionStatus::Resolved, $sanction->status);
        $this->assertSame(1, $sanction->matches_banned);
        $this->assertNotNull($sanction->resolved_at);
        $this->assertTrue($sanction->isActive());
        $this->assertTrue($player->fresh()->isSuspended());
    }

    public function test_a_second_yellow_queued_via_the_quick_add_batch_still_produces_a_single_double_yellow_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);

        // Mirrors the quick-add "segunda amarilla" flow: one yellow + its
        // auto-paired red queued together in the same batch submit.
        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'yellow_card', 'player_id' => $player->id],
                ['type' => 'yellow_card', 'player_id' => $player->id],
                ['type' => 'red_card', 'player_id' => $player->id],
            ],
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertSame(1, Sanction::query()->where('player_id', $player->id)->count());

        $sanction = Sanction::query()->where('player_id', $player->id)->first();
        $this->assertSame(SanctionType::DoubleYellow, $sanction->type);
        $this->assertSame(1, $sanction->matches_banned);
    }

    public function test_deleting_one_of_two_yellow_cards_removes_the_auto_generated_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);

        $first = MatchEvent::factory()->create([
            'match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'yellow_card',
        ]);
        MatchEvent::factory()->create([
            'match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'yellow_card',
        ]);

        $subject = ['team_id' => $player->team_id, 'player_id' => $player->id, 'coach_id' => null];
        app(SanctionService::class)->syncForSubject($match, $subject);

        $this->assertDatabaseHas('sanctions', ['player_id' => $player->id, 'type' => 'double_yellow']);

        $this->actingAs($user)->delete(route('events.destroy', $first))
            ->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseMissing('sanctions', ['player_id' => $player->id]);
    }

    // ── Regla 2: roja directa ────────────────────────────────────────────

    public function test_a_straight_red_card_creates_a_pending_sanction_with_no_assumed_duration(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'red_card',
            'player_id' => $player->id,
        ])->assertRedirect(route('matches.edit', $match));

        $sanction = Sanction::query()->where('player_id', $player->id)->first();

        $this->assertNotNull($sanction);
        $this->assertSame(SanctionType::RedCard, $sanction->type);
        $this->assertSame(SanctionStatus::Pending, $sanction->status);
        $this->assertNull($sanction->matches_banned);
        $this->assertNull($sanction->resolved_at);
        $this->assertTrue($player->fresh()->isSuspended());
    }

    public function test_the_committee_can_resolve_a_pending_sanction_with_a_number_of_matches_and_notes(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);
        $sanction = Sanction::factory()->create([
            'match_id' => $match->id,
            'match_event_id' => MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card']),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($user)->patch(route('sanctions.resolve', $sanction), [
            'matches_banned' => 3,
            'resolution_notes' => 'Agresión a un rival tras la expulsión.',
        ])->assertRedirect(route('sanctions.show', $sanction));

        $sanction->refresh();
        $this->assertSame(SanctionStatus::Resolved, $sanction->status);
        $this->assertSame(3, $sanction->matches_banned);
        $this->assertSame('Agresión a un rival tras la expulsión.', $sanction->resolution_notes);
        $this->assertNotNull($sanction->resolved_at);
    }

    public function test_a_resolved_sanction_cannot_be_resolved_again(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);
        $sanction = Sanction::factory()->resolved(2)->create([
            'match_id' => $match->id,
            'match_event_id' => MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card']),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($user)->patch(route('sanctions.resolve', $sanction), [
            'matches_banned' => 5,
        ])->assertRedirect();

        $this->assertSame(2, $sanction->fresh()->matches_banned);
    }

    public function test_deleting_a_pending_red_cards_event_removes_its_pending_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card',
        ]);
        app(SanctionService::class)->syncForSubject($match, ['team_id' => $player->team_id, 'player_id' => $player->id, 'coach_id' => null]);

        $this->assertDatabaseHas('sanctions', ['player_id' => $player->id, 'status' => 'pending']);

        $this->actingAs($user)->delete(route('events.destroy', $event))
            ->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseMissing('sanctions', ['player_id' => $player->id]);
    }

    public function test_deleting_a_red_cards_event_is_blocked_once_its_sanction_has_been_resolved(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card',
        ]);
        Sanction::factory()->resolved(2)->create([
            'match_id' => $match->id,
            'match_event_id' => $event->id,
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $event))
            ->assertRedirect(route('matches.edit', $match))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('match_events', ['id' => $event->id]);
        $this->assertDatabaseHas('sanctions', ['player_id' => $player->id, 'status' => 'resolved']);
    }

    // ── Regla 3: DT ──────────────────────────────────────────────────────

    public function test_a_coachs_straight_red_creates_a_pending_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $coach] = $this->makeMatchWithCoach($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'red_card',
            'coach_id' => $coach->id,
        ])->assertRedirect(route('matches.edit', $match));

        $sanction = Sanction::query()->where('coach_id', $coach->id)->first();

        $this->assertNotNull($sanction);
        $this->assertSame(SanctionType::RedCard, $sanction->type);
        $this->assertSame(SanctionStatus::Pending, $sanction->status);
        $this->assertTrue($coach->fresh()->isSuspended());
    }

    public function test_the_committee_can_resolve_a_coachs_sanction_with_matches_and_a_fine(): void
    {
        $user = User::factory()->create();
        [$match, $coach] = $this->makeMatchWithCoach($user);
        $sanction = Sanction::factory()->create([
            'match_id' => $match->id,
            'match_event_id' => MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $coach->team_id, 'coach_id' => $coach->id, 'player_id' => null, 'type' => 'red_card']),
            'team_id' => $coach->team_id,
            'player_id' => null,
            'coach_id' => $coach->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($user)->patch(route('sanctions.resolve', $sanction), [
            'matches_banned' => 4,
            'fine_amount' => 15000.50,
            'resolution_notes' => 'Conducta antideportiva hacia el árbitro.',
        ])->assertRedirect(route('sanctions.show', $sanction));

        $sanction->refresh();
        $this->assertSame(4, $sanction->matches_banned);
        $this->assertSame('15000.50', (string) $sanction->fine_amount);
    }

    public function test_a_fine_cannot_be_set_when_resolving_a_players_sanction(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);
        $sanction = Sanction::factory()->create([
            'match_id' => $match->id,
            'match_event_id' => MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card']),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($user)->patch(route('sanctions.resolve', $sanction), [
            'matches_banned' => 2,
            'fine_amount' => 5000,
        ])->assertSessionHasErrors('fine_amount');

        $this->assertSame('pending', $sanction->fresh()->status->value);
    }

    // ── Consultar el estado de una sanción ──────────────────────────────

    public function test_a_sanction_is_automatically_fulfilled_as_the_teams_following_matches_finish(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);
        $match->update(['round_number' => 1]);

        $sanction = Sanction::factory()->resolved(2)->create([
            'match_id' => $match->id,
            'match_event_id' => MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card']),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->assertTrue($player->fresh()->isSuspended());

        // Neither of the two following matches has been played yet -- no
        // fecha served, still fully suspended.
        $next = $this->makeFollowUpMatch($match, 2);
        $afterNext = $this->makeFollowUpMatch($match, 3);
        $this->assertSame(0, $sanction->matchesServedCount());
        $this->assertTrue($player->fresh()->isSuspended());

        // The first following match finishes on its own (e.g. through the
        // normal result-entry flow) -- nothing about the sanction is
        // touched manually, it's simply recomputed from the calendar.
        $next->update(['status' => MatchStatus::Finished, 'home_score' => 1, 'away_score' => 0]);
        $this->assertSame(1, $sanction->matchesServedCount());
        $this->assertTrue($player->fresh()->isSuspended());

        $afterNext->update(['status' => MatchStatus::Finished, 'home_score' => 2, 'away_score' => 1]);
        $this->assertSame(2, $sanction->matchesServedCount());
        $this->assertTrue($sanction->isFulfilled());
        $this->assertFalse($player->fresh()->isSuspended());
    }

    public function test_a_match_from_before_the_sanctioned_red_card_is_never_blocked(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);
        $match->update(['round_number' => 3]);

        // Created AFTER the sanction in the database, but scheduled for an
        // EARLIER round -- ordering must follow round_number, not id/insert
        // order, exactly the bug this rule fixes.
        $earlierMatch = $this->makeFollowUpMatch($match, 1);

        $sanction = Sanction::factory()->resolved(3)->create([
            'match_id' => $match->id,
            'match_event_id' => MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card']),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->assertFalse($sanction->blocksMatch($earlierMatch->id));

        $this->actingAs($user)->post(route('matches.events.store', $earlierMatch), [
            'type' => 'goal',
            'player_id' => $player->id,
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_a_window_matchs_label_keeps_showing_its_own_fecha_number_even_after_the_whole_sanction_is_later_fulfilled(): void
    {
        $user = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($user);
        $match->update(['round_number' => 1]);

        $sanction = Sanction::factory()->resolved(2)->create([
            'match_id' => $match->id,
            'match_event_id' => MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card']),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $first = $this->makeFollowUpMatch($match, 2);
        $second = $this->makeFollowUpMatch($match, 3);

        // Each window match IS a specific fecha -- the first one is always
        // "1 de 2", the second always "2 de 2", whether or not either has
        // actually been played yet.
        $this->assertSame('Cumpliendo sanción (1 de 2 fechas)', $sanction->stateLabelForMatch($first->id));
        $this->assertSame('Cumpliendo sanción (2 de 2 fechas)', $sanction->stateLabelForMatch($second->id));

        $first->update(['status' => MatchStatus::Finished, 'home_score' => 1, 'away_score' => 0]);
        $this->assertSame('Cumpliendo sanción (1 de 2 fechas)', $sanction->stateLabelForMatch($first->id));
        $this->assertSame('Cumpliendo sanción (2 de 2 fechas)', $sanction->stateLabelForMatch($second->id));

        // The sanction is now globally fulfilled -- stateLabel() (the
        // general, non-match-specific status) correctly says so -- but
        // stateLabelForMatch() for EITHER window match still reports its
        // own fecha number, never "Sanción cumplida".
        $second->update(['status' => MatchStatus::Finished, 'home_score' => 2, 'away_score' => 1]);
        $this->assertTrue($sanction->isFulfilled());
        $this->assertSame('Sanción cumplida', $sanction->stateLabel());
        $this->assertSame('Cumpliendo sanción (1 de 2 fechas)', $sanction->stateLabelForMatch($first->id));
        $this->assertSame('Cumpliendo sanción (2 de 2 fechas)', $sanction->stateLabelForMatch($second->id));
    }

    // ── Autorización ─────────────────────────────────────────────────────

    public function test_a_user_cannot_view_or_resolve_another_users_sanction(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        [$match, $player] = $this->makeMatchWithPlayers($owner);
        $sanction = Sanction::factory()->create([
            'match_id' => $match->id,
            'match_event_id' => MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card']),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($intruder)->get(route('sanctions.show', $sanction))->assertForbidden();

        $this->actingAs($intruder)->patch(route('sanctions.resolve', $sanction), [
            'matches_banned' => 1,
        ])->assertForbidden();

        $this->assertSame('pending', $sanction->fresh()->status->value);
    }
}
