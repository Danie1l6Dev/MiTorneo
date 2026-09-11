<?php

namespace Tests\Feature\Public;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\LeagueSchedule;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public portal (routes/public.php) lets anyone with the link consult a
 * tournament -- categories, groups, standings, calendar, results, and player
 * statistics -- without logging in, and without ever exposing a way to
 * modify anything or leaking data the organizer never intended to publish
 * (player document numbers, admin routes, ...).
 */
class PublicTournamentPortalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, tournament: Tournament, category: Category, groupA: Group, groupB: Group, home: Team, away: Team, other: Team, player: Player, phase: CompetitionPhase, match: TournamentMatch}
     */
    private function makeFullTournament(): array
    {
        $user = User::factory()->create();

        $tournament = Tournament::factory()->for($user)->create([
            'name' => 'Campeonato Maicao 2026',
            'slug' => 'campeonato-maicao-2026',
        ]);

        $category = Category::factory()->for($tournament)->usingGroups()->create(['name' => 'Sub-15']);
        $groupA = Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo A']);
        $groupB = Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo B']);

        $home = Team::factory()->for($tournament)->for($category)->create(['name' => 'Tigres FC', 'group_id' => $groupA->id]);
        $away = Team::factory()->for($tournament)->for($category)->create(['name' => 'Leones FC', 'group_id' => $groupA->id]);
        $other = Team::factory()->for($tournament)->for($category)->create(['name' => 'Águilas FC', 'group_id' => $groupB->id]);

        $player = Player::factory()->for($home)->create([
            'full_name' => 'Carlos Gómez',
            'document_number' => '1234567890',
        ]);

        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);

        // A LeagueSchedule row is what the calendar tab actually reads from
        // (PhaseBoardService::scheduleViews() queries $phase->leagueSchedules(),
        // never the matches directly) -- a match alone, with no schedule, would
        // silently show as "no calendar generated yet".
        $schedule = LeagueSchedule::factory()->for($phase, 'competitionPhase')->for($tournament)->create(['group_id' => $groupA->id]);

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'group_id' => $groupA->id,
            'league_schedule_id' => $schedule->id,
            'round_number' => 1,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 2,
            'away_score' => 1,
            'status' => MatchStatus::Finished,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::Goal,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::YellowCard,
        ]);

        return compact('user', 'tournament', 'category', 'groupA', 'groupB', 'home', 'away', 'other', 'player', 'phase', 'match');
    }

    // ── Acceso público sin autenticación ────────────────────────────────

    public function test_a_guest_can_view_a_tournaments_public_page(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.show', $data['tournament']))
            ->assertOk()
            ->assertSee('Campeonato Maicao 2026')
            ->assertSee('Sub-15');
    }

    public function test_the_tournament_is_reachable_by_its_readable_slug_url(): void
    {
        $this->makeFullTournament();

        $this->get('/public/torneos/campeonato-maicao-2026')->assertOk();
    }

    // ── Categorías y grupos ──────────────────────────────────────────────

    public function test_a_guest_can_view_a_categorys_page_with_its_groups_and_teams(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.categories.show', [$data['tournament'], $data['category']]))
            ->assertOk()
            ->assertSee('Grupo A')
            ->assertSee('Grupo B')
            ->assertSee('Tigres FC')
            ->assertSee('Águilas FC');
    }

    public function test_a_guest_can_navigate_from_the_category_page_into_a_phase(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.categories.show', [$data['tournament'], $data['category']]))
            ->assertOk()
            ->assertSee(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']]), false);
    }

    // ── Tablas de posiciones (por grupo) ─────────────────────────────────

    public function test_a_guest_can_view_the_standings_table_for_each_group_of_a_phase(): void
    {
        $data = $this->makeFullTournament();

        // Group A has a finished 2-1 result; group B's table still shows
        // its own team even with no matches played yet -- both tables are
        // visible on the same "tabla" section, one per group.
        $this->get(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']]))
            ->assertOk()
            ->assertSee('Grupo A')
            ->assertSee('Grupo B')
            ->assertSee('Tigres FC')
            ->assertSee('Leones FC')
            ->assertSee('Águilas FC');
    }

    // ── Calendario y resultados ──────────────────────────────────────────

    public function test_a_guest_can_view_the_calendar_with_a_finished_result(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']]))
            ->assertOk()
            ->assertSee(__('Calendario'))
            // The match-card badge upper-cases the status label (mb_strtoupper),
            // same as the admin calendar view.
            ->assertSee(mb_strtoupper(MatchStatus::Finished->label()));
    }

    // ── Estadísticas (goleadores/asistencias/amarillas/rojas) ────────────

    public function test_a_guest_can_view_the_goalscorer_leaderboard(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']]).'?view=goal')
            ->assertOk()
            ->assertSee(MatchEventType::Goal->leaderboardTitle())
            ->assertSee('Carlos Gómez');
    }

    public function test_a_guest_can_view_the_yellow_card_leaderboard(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']]).'?view=yellow_card')
            ->assertOk()
            ->assertSee(MatchEventType::YellowCard->leaderboardTitle())
            ->assertSee('Carlos Gómez');
    }

    public function test_the_assist_leaderboard_does_not_show_a_player_who_only_scored(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']]).'?view=assist')
            ->assertOk()
            ->assertSee(MatchEventType::Assist->leaderboardTitle())
            ->assertDontSee('Carlos Gómez');
    }

    public function test_statistics_reuse_the_same_service_and_are_scoped_by_group(): void
    {
        $data = $this->makeFullTournament();

        // Filtering by group B (which never played) must not show group A's
        // goalscorer -- proves the group filter (CompetitionStatisticsService)
        // is actually wired through on the public page, not just displayed.
        $this->get(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']])."?view=goal&group={$data['groupB']->id}")
            ->assertOk()
            ->assertDontSee('Carlos Gómez');
    }

    // ── Seguridad: nada sensible ni administrativo se expone ─────────────

    public function test_a_players_document_number_is_never_shown_on_the_public_leaderboard(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']]).'?view=goal')
            ->assertOk()
            ->assertSee('Carlos Gómez')
            ->assertDontSee('1234567890');
    }

    public function test_the_public_pages_never_render_admin_edit_or_delete_actions(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('public.tournaments.show', $data['tournament']))
            ->assertOk()
            ->assertDontSee(__('Editar'))
            ->assertDontSee(__('Eliminar'));

        $this->get(route('public.tournaments.categories.show', [$data['tournament'], $data['category']]))
            ->assertOk()
            ->assertDontSee(__('Editar'))
            ->assertDontSee(__('Eliminar'));

        $this->get(route('public.tournaments.phases.show', [$data['tournament'], $data['phase']]))
            ->assertOk()
            ->assertDontSee(__('Editar'))
            ->assertDontSee(__('Eliminar'))
            ->assertDontSee(__('Generar calendario'));
    }

    // ── 404s: nada fuera del propio torneo/categoría/fase se filtra ──────

    public function test_an_unknown_tournament_slug_is_a_404(): void
    {
        $this->get('/public/torneos/no-existe-2026')->assertNotFound();
    }

    public function test_a_category_from_a_different_tournament_is_a_404(): void
    {
        $data = $this->makeFullTournament();
        $otherTournament = Tournament::factory()->create();

        $this->get(route('public.tournaments.categories.show', [$otherTournament, $data['category']]))
            ->assertNotFound();
    }

    public function test_a_phase_from_a_different_tournament_is_a_404(): void
    {
        $data = $this->makeFullTournament();
        $otherTournament = Tournament::factory()->create();

        $this->get(route('public.tournaments.phases.show', [$otherTournament, $data['phase']]))
            ->assertNotFound();
    }

    // ── Impedir acciones de modificación desde la ruta pública ───────────

    public function test_no_write_verb_is_registered_under_the_public_prefix(): void
    {
        $data = $this->makeFullTournament();
        $path = '/public/torneos/'.$data['tournament']->slug;

        $this->post($path)->assertStatus(405);
        $this->put($path)->assertStatus(405);
        $this->patch($path)->assertStatus(405);
        $this->delete($path)->assertStatus(405);
    }

    public function test_a_guest_cannot_register_a_match_result_using_ids_learned_from_the_public_pages(): void
    {
        $data = $this->makeFullTournament();

        $this->patch(route('matches.result.update', $data['match']), [
            'home_score' => 9,
            'away_score' => 0,
            'status' => MatchStatus::Finished->value,
        ])->assertRedirect(route('login'));

        $this->assertSame(2, $data['match']->fresh()->home_score);
    }

    public function test_a_guest_cannot_reach_the_admin_edit_page_for_a_team_seen_publicly(): void
    {
        $data = $this->makeFullTournament();

        $this->get(route('teams.edit', $data['home']))->assertRedirect(route('login'));
    }

    public function test_a_guest_cannot_delete_a_phase_seen_publicly(): void
    {
        $data = $this->makeFullTournament();

        $this->delete(route('phases.destroy', $data['phase']))->assertRedirect(route('login'));

        $this->assertNotNull($data['phase']->fresh());
    }
}
