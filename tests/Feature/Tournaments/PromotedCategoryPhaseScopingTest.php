<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Models\Category;
use App\Models\Club;
use App\Models\CompetitionPhase;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\CategoryTournamentPromotionService;
use App\Services\PhaseEligibilityService;
use App\Services\StandingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T02-03 of docs/plan-reestructuracion/02-unificacion-categorias-torneo.md:
 * once a category lives in the catalog, it can be inscribed into more than
 * one tournament -- phase creation/chaining must stay scoped per tournament
 * instead of assuming a category belongs to exactly one.
 */
class PromotedCategoryPhaseScopingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Creating a phase via the bare categories.phases.store route (no
     * tournament in the URL) can't tell which of two tournaments it's for
     * -- rather than guessing, it's refused outright. Real support for
     * creating a phase for a specific tournament out of a shared category
     * is future work (see resolveTournament()'s docblock in
     * CompetitionPhaseController) -- this locks in today's guard rail so a
     * future change can't silently start guessing.
     */
    public function test_a_category_shared_by_two_tournaments_refuses_an_ambiguous_phase_creation(): void
    {
        $organizer = User::factory()->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'name' => 'Infantil']);
        $tournamentA = Tournament::factory()->for($organizer)->create();
        $tournamentB = Tournament::factory()->for($organizer)->create();
        $tournamentA->globalCategories()->attach($category->id);
        $tournamentB->globalCategories()->attach($category->id);

        $this->actingAs($organizer)
            ->post(route('categories.phases.store', $category), ['name' => 'Liga', 'type' => 'league'])
            ->assertStatus(422);

        $this->assertSame(0, CompetitionPhase::query()->count());
    }

    public function test_the_first_phase_team_count_is_scoped_to_this_tournaments_own_roster_not_every_team_the_category_has(): void
    {
        $organizer = User::factory()->create();
        $club = Club::factory()->for($organizer)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'name' => 'Infantil', 'uses_groups' => false]);
        $tournament = Tournament::factory()->for($organizer)->create();
        $tournament->globalCategories()->attach($category->id);

        // 4 teams under the catalog category overall, but only 2 actually
        // inscribed in this tournament -- a knockout here needs a
        // power-of-two count of the TOURNAMENT's own roster (2), not the
        // category's global total (4, which isn't itself a power of two
        // anyway... it is, so use an odd extra team to make the point
        // unambiguous: 3 total, 2 inscribed).
        $teams = Team::factory()->count(3)->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null]);
        $tournament->globalTeams()->attach($teams->take(2)->pluck('id'));

        $this->actingAs($organizer)
            ->post(route('categories.phases.store', $category), ['name' => 'Copa', 'type' => 'knockout'])
            ->assertRedirect();

        $phase = CompetitionPhase::query()->where('tournament_id', $tournament->id)->sole();
        $this->assertSame(CompetitionPhaseType::Knockout, $phase->type);
        $this->assertSame(1, $phase->matches()->count());
    }

    public function test_declaring_a_champion_in_one_tournament_does_not_block_the_other_tournaments_chain(): void
    {
        $organizer = User::factory()->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'name' => 'Infantil']);
        $tournamentA = Tournament::factory()->for($organizer)->create();
        $tournamentB = Tournament::factory()->for($organizer)->create();
        $tournamentA->globalCategories()->attach($category->id);
        $tournamentB->globalCategories()->attach($category->id);

        $phaseA = CompetitionPhase::factory()->for($category)->for($tournamentA)->create(['type' => CompetitionPhaseType::League]);
        $phaseB = CompetitionPhase::factory()->for($category)->for($tournamentB)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $nextPhaseA = CompetitionPhase::factory()->for($category)->for($tournamentA)->create(['order' => 2]);

        $service = new PhaseEligibilityService;

        $this->assertTrue($service->hasNextPhase($phaseA));
        $this->assertFalse($service->hasNextPhase($phaseB));
        $this->assertNull($service->nextPhase($phaseB));
    }

    public function test_a_category_not_yet_inscribed_in_any_tournament_cannot_get_a_phase(): void
    {
        $organizer = User::factory()->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id]);

        $this->actingAs($organizer)
            ->post(route('categories.phases.store', $category), ['name' => 'Liga', 'type' => 'league'])
            ->assertForbidden();
    }

    /**
     * Real bug found testing T02-01 against a real copy of production data:
     * the category page's whole "Fases" section was still gated behind
     * `$category->tournament_id` (a leftover from Tema 01, when a global
     * category genuinely couldn't have phases yet) -- once every category
     * gets promoted, that condition is never true again, so it silently
     * hid every category's existing phases and match history.
     */
    public function test_a_promoted_categorys_existing_phases_are_still_visible_on_its_page(): void
    {
        $organizer = User::factory()->create();
        $tournament = Tournament::factory()->for($organizer)->create();
        $category = Category::factory()->for($tournament)->create(['name' => 'Teterito']);
        $phase = CompetitionPhase::factory()->for($category)->for($tournament)->create(['name' => 'Liga Apertura']);

        (new CategoryTournamentPromotionService)->run(execute: true);

        $category->refresh();
        $this->assertNull($category->tournament_id);

        $response = $this->actingAs($organizer)->get(route('categories.show', $category));

        $response->assertOk()->assertSee('LIGA APERTURA');
    }

    /**
     * Real bug found testing T02-01 against a real copy of production data:
     * the phase page's breadcrumb read
     * `$phase->category->tournament->name` -- a promoted category's
     * `tournament` is null, so opening ANY existing phase crashed with
     * "Attempt to read property on null". Fixed to use `$phase->tournament`
     * directly (a CompetitionPhase always has its own concrete tournament).
     */
    public function test_a_promoted_categorys_phase_page_still_renders(): void
    {
        $organizer = User::factory()->create();
        $tournament = Tournament::factory()->for($organizer)->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($category)->for($tournament)->create(['name' => 'Liga Apertura']);

        (new CategoryTournamentPromotionService)->run(execute: true);

        $this->actingAs($organizer)
            ->get(route('phases.show', $phase))
            ->assertOk()
            ->assertSee($tournament->name);
    }

    /**
     * Real bug found testing T02-01 against a real copy of production data:
     * `SanctionPolicy` authorized via `$sanction->team->tournament->user_id`
     * -- a promoted team's `tournament` is null, so viewing/resolving any
     * sanction against a promoted team's roster crashed with "Attempt to
     * read property on null" instead of a clean 403/200. Fixed by
     * authorizing via `$sanction->match->tournament` instead (a
     * TournamentMatch always has its own concrete tournament).
     */
    public function test_a_sanction_against_a_promoted_teams_player_can_still_be_viewed_by_its_owner(): void
    {
        $organizer = User::factory()->create();
        $tournament = Tournament::factory()->for($organizer)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $team = Team::factory()->for($tournament)->for($category)->create();
        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $team->id,
            'away_team_id' => Team::factory()->for($tournament)->for($category)->create()->id,
        ]);
        $sanction = Sanction::factory()->create([
            'match_id' => $match->id,
            'team_id' => $team->id,
            'player_id' => Player::factory()->for($team)->create()->id,
        ]);

        (new CategoryTournamentPromotionService)->run(execute: true);

        $team->refresh();
        $this->assertNull($team->tournament_id);

        $this->actingAs($organizer)
            ->get(route('sanctions.show', $sanction))
            ->assertOk();
    }

    public function test_the_public_portal_shows_only_this_tournaments_roster_for_a_shared_category(): void
    {
        $organizer = User::factory()->create();
        $club = Club::factory()->for($organizer)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'uses_groups' => false]);
        $tournamentA = Tournament::factory()->for($organizer)->create();
        $tournamentB = Tournament::factory()->for($organizer)->create();
        $tournamentA->globalCategories()->attach($category->id);
        $tournamentB->globalCategories()->attach($category->id);

        $teamA = Team::factory()->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null, 'name' => 'Solo en A']);
        $teamB = Team::factory()->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null, 'name' => 'Solo en B']);
        $tournamentA->globalTeams()->attach($teamA->id);
        $tournamentB->globalTeams()->attach($teamB->id);

        $response = $this->get(route('public.tournaments.categories.show', [$tournamentA, $category]));

        $response->assertOk()->assertSee('SOLO EN A')->assertDontSee('Solo en B');
    }

    /**
     * T02-11: real bug found auditing the code before deploying to
     * production. A promoted category's catalog can have MORE teams than
     * what this tournament actually inscribed via "Elegir planteles" (an
     * organizer can leave a team out on purpose, or add a new team to the
     * club/category after the roster was already picked) -- generating a
     * league schedule from the category's raw `teams()` instead of its
     * tournament-inscribed roster would schedule matches for a team that
     * was never chosen to play in this tournament.
     */
    public function test_generating_a_league_schedule_only_includes_teams_inscribed_in_this_tournament(): void
    {
        $organizer = User::factory()->create();
        $club = Club::factory()->for($organizer)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'uses_groups' => false]);
        $tournament = Tournament::factory()->for($organizer)->create();
        $tournament->globalCategories()->attach($category->id);

        $inscribed = Team::factory()->count(2)->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null]);
        $tournament->globalTeams()->attach($inscribed->pluck('id'));

        // In the catalog, but never inscribed in THIS tournament.
        $leftOut = Team::factory()->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null, 'name' => 'Nunca inscripto']);

        $phase = CompetitionPhase::factory()->for($category)->for($tournament)->create(['type' => CompetitionPhaseType::League]);

        $this->actingAs($organizer)
            ->post(route('phases.schedule.store', $phase), ['format' => 'single_round'])
            ->assertRedirect();

        $matchTeamIds = TournamentMatch::query()
            ->where('competition_phase_id', $phase->id)
            ->get(['home_team_id', 'away_team_id'])
            ->flatMap(fn (TournamentMatch $match) => [$match->home_team_id, $match->away_team_id])
            ->unique();

        $this->assertFalse($matchTeamIds->contains($leftOut->id));
        $this->assertTrue($inscribed->pluck('id')->every(fn ($id) => $matchTeamIds->contains($id)));
    }

    public function test_standings_only_include_teams_inscribed_in_this_tournament(): void
    {
        $organizer = User::factory()->create();
        $club = Club::factory()->for($organizer)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'uses_groups' => false]);
        $tournament = Tournament::factory()->for($organizer)->create();
        $tournament->globalCategories()->attach($category->id);

        $teamA = Team::factory()->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null]);
        $teamB = Team::factory()->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null]);
        $tournament->globalTeams()->attach([$teamA->id, $teamB->id]);

        $leftOut = Team::factory()->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null, 'name' => 'Nunca inscripto']);

        $phase = CompetitionPhase::factory()->for($category)->for($tournament)->create(['type' => CompetitionPhaseType::League]);

        $tables = (new StandingsService)->tablesForPhase($phase);

        $teamIdsInTable = collect($tables[0]['rows'])->pluck('team.id');
        $this->assertFalse($teamIdsInTable->contains($leftOut->id));
        $this->assertTrue($teamIdsInTable->contains($teamA->id));
        $this->assertTrue($teamIdsInTable->contains($teamB->id));
    }

    /**
     * T02-11: real near-miss bug found auditing before deploying to
     * production. The public portal's TOURNAMENT page (not the category
     * page, already fixed by T02-06) still read `$tournament->categories`
     * (the legacy HasMany) directly -- once a tournament's categories are
     * promoted, that's always empty, so the public page anyone can open
     * would have shown "no categories" for every real promoted tournament.
     */
    public function test_the_public_tournament_page_shows_promoted_categories(): void
    {
        $organizer = User::factory()->create();
        $club = Club::factory()->for($organizer)->create();
        $tournament = Tournament::factory()->for($organizer)->create(['slug' => 'torneo-promovido']);
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'uses_groups' => false, 'name' => 'Infantil']);
        $team = Team::factory()->for($category)->for($club)->create(['tournament_id' => null, 'group_id' => null]);
        $tournament->globalCategories()->attach($category->id);
        $tournament->globalTeams()->attach($team->id);

        $response = $this->get(route('public.tournaments.show', $tournament));

        $response->assertOk()
            ->assertSee('INFANTIL')
            ->assertDontSee('todavía no tiene categorías publicadas');
    }
}
