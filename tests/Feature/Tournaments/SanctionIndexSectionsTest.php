<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\SanctionStatus;
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
use App\Services\TeamExpulsionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sanctions index (pages/sanctions/index.blade.php) splits the
 * organizer's sanctions into three sections instead of one flat list:
 * "Faltan por resolver" (Pending), "Ya tienen resolución" (Resolved but
 * still being served -- Sanction::isActive()), and "Ya cumplidas" (Resolved
 * and fully served -- Sanction::isFulfilled()).
 */
class SanctionIndexSectionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Team, 1: TournamentMatch}
     */
    private function makeTeamWithOriginMatch(User $user): array
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
            'status' => MatchStatus::Finished,
        ]);

        return [$team, $match];
    }

    private function makeFollowUpMatch(TournamentMatch $origin, int $roundNumber, MatchStatus $status): TournamentMatch
    {
        return TournamentMatch::factory()->for($origin->competitionPhase)->create([
            'tournament_id' => $origin->tournament_id,
            'category_id' => $origin->category_id,
            'home_team_id' => $origin->home_team_id,
            'away_team_id' => $origin->away_team_id,
            'round_number' => $roundNumber,
            'status' => $status,
        ]);
    }

    private function makeSanction(Team $team, TournamentMatch $match, SanctionStatus $status, ?int $matchesBanned = null, string $fullName = 'Jugador Sancionado'): Sanction
    {
        $player = Player::factory()->for($team)->create(['full_name' => $fullName]);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $team->id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        return Sanction::factory()->create([
            'match_id' => $match->id,
            'match_event_id' => $event->id,
            'team_id' => $team->id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
            'status' => $status,
            'matches_banned' => $matchesBanned,
            'resolved_at' => $status === SanctionStatus::Resolved ? now() : null,
        ]);
    }

    // ── Faltan por resolver ──────────────────────────────────────────────

    public function test_a_pending_sanction_appears_in_the_faltan_por_resolver_section(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($team, $match, SanctionStatus::Pending, fullName: 'Pendiente Pérez');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeTextInOrder([__('Faltan por resolver'), 'PENDIENTE PÉREZ'])
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('activeSanctions', fn ($sanctions) => $sanctions->isEmpty())
            ->assertViewHas('fulfilledSanctions', fn ($sanctions) => $sanctions->isEmpty());
    }

    // ── Ya tienen resolución (activa, aún cumpliendo) ─────────────────────

    public function test_a_resolved_but_still_serving_sanction_appears_in_the_ya_tienen_resolucion_section(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        // Not finished yet -- the single banned fecha hasn't been served.
        $this->makeFollowUpMatch($match, 2, MatchStatus::Scheduled);

        $this->makeSanction($team, $match, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Activo Gómez');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeTextInOrder([__('Ya tienen resolución'), 'ACTIVO GÓMEZ'])
            ->assertViewHas('activeSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->isEmpty())
            ->assertViewHas('fulfilledSanctions', fn ($sanctions) => $sanctions->isEmpty());
    }

    // ── Ya cumplidas ─────────────────────────────────────────────────────

    public function test_a_fully_served_sanction_appears_in_the_ya_cumplidas_section(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        // Finished -- the single banned fecha has been served.
        $this->makeFollowUpMatch($match, 2, MatchStatus::Finished);

        $this->makeSanction($team, $match, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Cumplido Ruiz');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeTextInOrder([__('Ya cumplidas'), 'CUMPLIDO RUIZ'])
            ->assertViewHas('fulfilledSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->isEmpty())
            ->assertViewHas('activeSanctions', fn ($sanctions) => $sanctions->isEmpty());
    }

    // ── Secciones vacías no se muestran ───────────────────────────────────

    public function test_a_section_with_no_sanctions_is_not_rendered(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($team, $match, SanctionStatus::Pending);

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeText(__('Faltan por resolver'))
            ->assertDontSeeText(__('Ya tienen resolución'))
            ->assertDontSeeText(__('Ya cumplidas'));
    }

    // ── Las tres secciones a la vez ───────────────────────────────────────

    public function test_all_three_sections_render_together_with_the_right_sanction_in_each(): void
    {
        $user = User::factory()->create();

        [$teamPending, $matchPending] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamPending, $matchPending, SanctionStatus::Pending, fullName: 'Pendiente Pérez');

        [$teamActive, $matchActive] = $this->makeTeamWithOriginMatch($user);
        $this->makeFollowUpMatch($matchActive, 2, MatchStatus::Scheduled);
        $this->makeSanction($teamActive, $matchActive, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Activo Gómez');

        [$teamFulfilled, $matchFulfilled] = $this->makeTeamWithOriginMatch($user);
        $this->makeFollowUpMatch($matchFulfilled, 2, MatchStatus::Finished);
        $this->makeSanction($teamFulfilled, $matchFulfilled, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Cumplido Ruiz');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('activeSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('fulfilledSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertSeeText('PENDIENTE PÉREZ')
            ->assertSeeText('ACTIVO GÓMEZ')
            ->assertSeeText('CUMPLIDO RUIZ');
    }

    public function test_the_stat_cards_count_matches_the_sections(): void
    {
        $user = User::factory()->create();

        [$teamOne, $matchOne] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamOne, $matchOne, SanctionStatus::Pending);

        [$teamTwo, $matchTwo] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamTwo, $matchTwo, SanctionStatus::Pending);

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()->assertSeeText('2');
    }

    public function test_the_stat_cards_also_count_team_expulsions(): void
    {
        $user = User::factory()->create();

        [$teamOne, $matchOne] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamOne, $matchOne, SanctionStatus::Pending);

        // An expulsion with no resolution attached yet reads as pending,
        // regardless of whether its category's matches are done.
        [$teamTwo, $matchTwo] = $this->makeTeamWithOriginMatch($user);
        app(TeamExpulsionService::class)->expel($teamTwo, $matchTwo->tournament, null);

        // Resolved, but its category still has a match left to play
        // (between two OTHER teams -- teamThree's own expulsion doesn't
        // touch it) -- active.
        [$teamThree, $matchThree] = $this->makeTeamWithOriginMatch($user);
        $otherTeamA = Team::factory()->for($matchThree->tournament)->for($matchThree->category)->create();
        $otherTeamB = Team::factory()->for($matchThree->tournament)->for($matchThree->category)->create();
        TournamentMatch::factory()->for($matchThree->competitionPhase)->create([
            'tournament_id' => $matchThree->tournament_id,
            'category_id' => $matchThree->category_id,
            'home_team_id' => $otherTeamA->id,
            'away_team_id' => $otherTeamB->id,
            'round_number' => 2,
            'status' => MatchStatus::Scheduled,
        ]);
        app(TeamExpulsionService::class)->expel($teamThree, $matchThree->tournament, 'Motivo dado.');

        // Resolved, and its category's only match is already finished --
        // fulfilled.
        [$teamFour, $matchFour] = $this->makeTeamWithOriginMatch($user);
        app(TeamExpulsionService::class)->expel($teamFour, $matchFour->tournament, 'Motivo dado.');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertViewHas('totalPendingCount', 2)
            ->assertViewHas('totalActiveCount', 1)
            ->assertViewHas('totalFulfilledCount', 1);
    }

    // ── Seguridad/ownership ──────────────────────────────────────────────

    public function test_the_index_only_shows_the_authenticated_users_own_sanctions(): void
    {
        $owner = User::factory()->create();
        [$ownTeam, $ownMatch] = $this->makeTeamWithOriginMatch($owner);
        $this->makeSanction($ownTeam, $ownMatch, SanctionStatus::Pending, fullName: 'Propio Pérez');

        $otherUser = User::factory()->create();
        [$otherTeam, $otherMatch] = $this->makeTeamWithOriginMatch($otherUser);
        $this->makeSanction($otherTeam, $otherMatch, SanctionStatus::Pending, fullName: 'Ajeno Gómez');

        $response = $this->actingAs($owner)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeText('PROPIO PÉREZ')
            ->assertDontSeeText('Ajeno Gómez')
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->count() === 1);
    }

    public function test_a_guest_cannot_view_the_sanctions_index(): void
    {
        $this->get(route('sanctions.index'))->assertRedirect(route('login'));
    }

    /**
     * A category created through the real global-catalog flow
     * (CategoryController::store()) has $user_id set and $tournament_id
     * null -- see Category's own docblock and
     * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
     * Its teams inherit that: nothing in the real create flow ever
     * populates team.tournament_id anymore, only the tournament_team pivot
     * (Tournament::globalTeams()->attach(), see
     * TournamentCategoryController::updateTeams()) links a team to a
     * tournament. The index query must resolve ownership through
     * match.tournament (always set directly -- see TournamentMatchFactory)
     * rather than team.tournament, or a sanction on one of these teams
     * silently disappears from the page even though it was created
     * correctly.
     */
    public function test_a_sanction_still_appears_when_its_team_has_no_legacy_tournament_id(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();

        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $user->id,
            'uses_groups' => false,
        ]);
        $tournament->globalCategories()->attach($category->id);

        $phase = CompetitionPhase::factory()->for($category)->create(['tournament_id' => $tournament->id]);

        $team = Team::factory()->create(['category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $opponent = Team::factory()->create(['category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $tournament->globalTeams()->attach([$team->id, $opponent->id]);

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'round_number' => 1,
            'status' => MatchStatus::Finished,
        ]);

        $this->makeSanction($team, $match, SanctionStatus::Pending, fullName: 'Global Pérez');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeText('GLOBAL PÉREZ')
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->count() === 1);
    }

    // ── Badge del sidebar ────────────────────────────────────────────────

    /**
     * layouts/app/sidebar.blade.php shows a badge on the "Sanciones" item
     * with how many of the organizer's own sanctions are still Pending --
     * same count as the "Faltan por resolver" section. Only Pending counts:
     * a Resolved sanction (active or already fulfilled) doesn't need the
     * organizer's attention anymore, so it must not inflate the badge.
     */
    public function test_the_sidebar_badge_shows_the_pending_sanctions_count(): void
    {
        $user = User::factory()->create();

        [$teamPending, $matchPending] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamPending, $matchPending, SanctionStatus::Pending, fullName: 'Pendiente Uno');

        [$teamPendingTwo, $matchPendingTwo] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamPendingTwo, $matchPendingTwo, SanctionStatus::Pending, fullName: 'Pendiente Dos');

        [$teamResolved, $matchResolved] = $this->makeTeamWithOriginMatch($user);
        $this->makeFollowUpMatch($matchResolved, 2, MatchStatus::Finished);
        $this->makeSanction($teamResolved, $matchResolved, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Resuelto Ya');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertSee('data-flux-navlist-badge>2', false)->assertSeeInOrder(['Sanciones', '2']);
    }

    public function test_the_sidebar_shows_no_badge_when_there_are_no_pending_sanctions(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertDontSee('data-flux-navlist-badge>', false);
    }

    public function test_the_sidebar_badge_only_counts_the_authenticated_users_own_pending_sanctions(): void
    {
        $owner = User::factory()->create();
        [$ownTeam, $ownMatch] = $this->makeTeamWithOriginMatch($owner);
        $this->makeSanction($ownTeam, $ownMatch, SanctionStatus::Pending);

        $otherUser = User::factory()->create();
        [$otherTeam, $otherMatch] = $this->makeTeamWithOriginMatch($otherUser);
        $this->makeSanction($otherTeam, $otherMatch, SanctionStatus::Pending);
        $this->makeSanction($otherTeam, $otherMatch, SanctionStatus::Pending, fullName: 'Segundo Ajeno');

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk()->assertSeeInOrder(['Sanciones', '1']);
    }

    // ── Planteles expulsados: sección separada ────────────────────────────

    public function test_an_expelled_team_appears_in_its_own_section_separate_from_player_sanctions(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($team, $match, SanctionStatus::Pending, fullName: 'Pendiente Pérez');

        $tournament = $match->tournament;
        $expelledTeam = Team::factory()->for($tournament)->for($match->category)->create(['name' => 'Plantel Expulsado FC']);
        app(TeamExpulsionService::class)->expel($expelledTeam, $tournament, 'Agresión al árbitro.');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeTextInOrder([
                __('Sanciones a jugadores y DTs'), 'PENDIENTE PÉREZ',
                __('Planteles expulsados'), 'PLANTEL EXPULSADO FC', 'Agresión al árbitro.',
            ]);
    }

    public function test_the_expelled_teams_section_only_shows_the_authenticated_users_own_tournaments(): void
    {
        $owner = User::factory()->create();
        $ownTournament = Tournament::factory()->for($owner)->create();
        $ownCategory = Category::factory()->for($ownTournament)->create(['uses_groups' => false]);
        $ownTeam = Team::factory()->for($ownTournament)->for($ownCategory)->create(['name' => 'Propio Expulsado']);
        app(TeamExpulsionService::class)->expel($ownTeam, $ownTournament, null);

        $otherUser = User::factory()->create();
        $otherTournament = Tournament::factory()->for($otherUser)->create();
        $otherCategory = Category::factory()->for($otherTournament)->create(['uses_groups' => false]);
        $otherTeam = Team::factory()->for($otherTournament)->for($otherCategory)->create(['name' => 'Ajeno Expulsado']);
        app(TeamExpulsionService::class)->expel($otherTeam, $otherTournament, null);

        $response = $this->actingAs($owner)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeText('PROPIO EXPULSADO')
            ->assertDontSeeText('AJENO EXPULSADO');
    }

    public function test_reverting_an_expulsion_from_the_sanctions_index_removes_it_from_the_section(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $team = Team::factory()->for($tournament)->for($category)->create(['name' => 'Revertible FC']);
        app(TeamExpulsionService::class)->expel($team, $tournament, null);

        $this->actingAs($user)
            ->delete(route('tournaments.categories.teams.expel.destroy', [$tournament, $category, $team]))
            ->assertRedirect();

        // A fresh request, with no leftover flash session data from the
        // revert redirect above -- that flash message names the team too,
        // which would otherwise make assertDontSeeText('REVERTIBLE FC')
        // fail for a reason unrelated to what's actually being tested here
        // (whether the "Planteles expulsados" section itself still lists
        // it).
        $this->flushSession();

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        // Nothing else on the page (no player/coach sanctions either), so
        // it falls back to the page's single top-level empty state rather
        // than reaching the "Planteles expulsados" section's own -- either
        // way, the reverted team's name is gone.
        $response->assertOk()->assertDontSeeText('REVERTIBLE FC');
    }
}
