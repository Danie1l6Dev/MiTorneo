<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Referee;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The referee detail page (pages/referees/show.blade.php) lets the organizer
 * narrow the "partidos dirigidos" table by tournament, by that tournament's
 * own category, and/or by a date range -- all query-string driven
 * (RefereeController::show()).
 */
class RefereeMatchFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tournament: Tournament, category: Category}
     */
    private function makeTournamentAndCategory(User $user, string $tournamentName, string $categoryName): array
    {
        $tournament = Tournament::factory()->for($user)->create(['name' => $tournamentName]);
        $category = Category::factory()->for($tournament)->create(['name' => $categoryName, 'uses_groups' => false]);

        return compact('tournament', 'category');
    }

    private function makeDirectedMatch(Referee $referee, Category $category, ?string $scheduledAt = null): TournamentMatch
    {
        $tournament = $category->tournament;
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'referee_id' => $referee->id,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    // ── Filtro por torneo ────────────────────────────────────────────────

    public function test_filtering_by_a_tournament_only_shows_that_tournaments_matches(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();

        ['tournament' => $tournamentA, 'category' => $categoryA] = $this->makeTournamentAndCategory($user, 'Copa A', 'Categoría A');
        ['tournament' => $tournamentB, 'category' => $categoryB] = $this->makeTournamentAndCategory($user, 'Copa B', 'Categoría B');

        $matchA = $this->makeDirectedMatch($referee, $categoryA);
        $matchB = $this->makeDirectedMatch($referee, $categoryB);

        $response = $this->actingAs($user)->get(route('referees.show', $referee).'?tournament='.$tournamentA->id);

        // "Copa B" is still a valid option in the tournament <select> itself
        // (the referee worked it too), so only the returned $matches -- not
        // a page-wide assertDontSeeText -- can prove the table was filtered.
        $response->assertOk()
            ->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$matchA->id]);
    }

    public function test_the_tournament_filter_only_lists_tournaments_this_referee_actually_directed(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();

        ['category' => $categoryA] = $this->makeTournamentAndCategory($user, 'Torneo Dirigido', 'Cat A');
        // A second tournament this organizer owns, but the referee never worked.
        $this->makeTournamentAndCategory($user, 'Torneo Ajeno Al Árbitro', 'Cat B');

        $this->makeDirectedMatch($referee, $categoryA);

        $response = $this->actingAs($user)->get(route('referees.show', $referee));

        $response->assertOk()
            ->assertViewHas('tournaments', fn ($tournaments) => $tournaments->pluck('name')->all() === ['Torneo Dirigido']);
    }

    // ── Filtro por categoría (de ese torneo) ─────────────────────────────

    public function test_filtering_by_category_only_shows_that_categorys_matches(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        $tournament = Tournament::factory()->for($user)->create();
        $categoryOne = Category::factory()->for($tournament)->create(['name' => 'Sub-15']);
        $categoryTwo = Category::factory()->for($tournament)->create(['name' => 'Sub-20']);

        $matchOne = $this->makeDirectedMatch($referee, $categoryOne);
        $this->makeDirectedMatch($referee, $categoryTwo);

        $response = $this->actingAs($user)->get(
            route('referees.show', $referee)."?tournament={$tournament->id}&category={$categoryOne->id}"
        );

        $response->assertOk()
            ->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$matchOne->id]);
    }

    public function test_the_category_filter_is_scoped_to_the_selected_tournament_only(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();

        ['tournament' => $tournamentA, 'category' => $categoryA] = $this->makeTournamentAndCategory($user, 'Copa A', 'Categoría A');
        ['tournament' => $tournamentB, 'category' => $categoryB] = $this->makeTournamentAndCategory($user, 'Copa B', 'Categoría B');

        $this->makeDirectedMatch($referee, $categoryA);
        $this->makeDirectedMatch($referee, $categoryB);

        // Selecting tournament A must only offer category A as a choice --
        // category B (from a different tournament) has no business here.
        $response = $this->actingAs($user)->get(route('referees.show', $referee).'?tournament='.$tournamentA->id);

        $response->assertOk()
            ->assertViewHas('categories', fn ($categories) => $categories->pluck('id')->all() === [$categoryA->id]);

        // Requesting a foreign category id (belonging to tournament B) while
        // tournament A is selected is silently ignored, not an error and not
        // a leak -- same "unmatched id" pattern as the phase statistics
        // group filter.
        $response = $this->actingAs($user)->get(
            route('referees.show', $referee)."?tournament={$tournamentA->id}&category={$categoryB->id}"
        );

        $response->assertOk()
            ->assertViewHas('selectedCategory', fn ($category) => $category === null)
            ->assertSeeText('Categoría A');
    }

    public function test_no_category_can_be_selected_before_a_tournament_is(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        ['category' => $category] = $this->makeTournamentAndCategory($user, 'Copa A', 'Categoría A');
        $this->makeDirectedMatch($referee, $category);

        // No 'tournament' query param at all -- the category filter must not
        // silently apply on its own.
        $response = $this->actingAs($user)->get(route('referees.show', $referee).'?category='.$category->id);

        $response->assertOk()
            ->assertViewHas('categories', fn ($categories) => $categories->isEmpty())
            ->assertViewHas('selectedCategory', fn ($category) => $category === null);
    }

    // ── Filtro por fechas ────────────────────────────────────────────────

    public function test_filtering_by_a_date_range_only_shows_matches_scheduled_within_it(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        ['category' => $category] = $this->makeTournamentAndCategory($user, 'Copa A', 'Categoría A');

        $before = $this->makeDirectedMatch($referee, $category, '2026-01-05 10:00:00');
        $inRange = $this->makeDirectedMatch($referee, $category, '2026-02-15 10:00:00');
        $after = $this->makeDirectedMatch($referee, $category, '2026-03-20 10:00:00');

        $response = $this->actingAs($user)->get(
            route('referees.show', $referee).'?date_from=2026-02-01&date_to=2026-02-28'
        );

        $response->assertOk()
            ->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$inRange->id]);

        $this->assertNotContains($before->id, $response->viewData('matches')->pluck('id')->all());
        $this->assertNotContains($after->id, $response->viewData('matches')->pluck('id')->all());
    }

    public function test_an_open_ended_date_from_includes_every_match_from_that_date_onward(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        ['category' => $category] = $this->makeTournamentAndCategory($user, 'Copa A', 'Categoría A');

        $before = $this->makeDirectedMatch($referee, $category, '2026-01-05 10:00:00');
        $after = $this->makeDirectedMatch($referee, $category, '2026-06-20 10:00:00');

        $response = $this->actingAs($user)->get(route('referees.show', $referee).'?date_from=2026-02-01');

        $response->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$after->id]);
        $this->assertNotContains($before->id, $response->viewData('matches')->pluck('id')->all());
    }

    public function test_matches_with_no_scheduled_date_are_excluded_once_a_date_filter_is_applied(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        ['category' => $category] = $this->makeTournamentAndCategory($user, 'Copa A', 'Categoría A');

        $undated = $this->makeDirectedMatch($referee, $category, null);
        $dated = $this->makeDirectedMatch($referee, $category, '2026-02-15 10:00:00');

        $response = $this->actingAs($user)->get(route('referees.show', $referee).'?date_from=2026-01-01');

        $response->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$dated->id]);
        $this->assertNotContains($undated->id, $response->viewData('matches')->pluck('id')->all());
    }

    // ── Filtros combinados ───────────────────────────────────────────────

    public function test_tournament_and_date_filters_combine(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();

        ['tournament' => $tournamentA, 'category' => $categoryA] = $this->makeTournamentAndCategory($user, 'Copa A', 'Categoría A');
        ['category' => $categoryB] = $this->makeTournamentAndCategory($user, 'Copa B', 'Categoría B');

        $matchAInRange = $this->makeDirectedMatch($referee, $categoryA, '2026-02-15 10:00:00');
        $this->makeDirectedMatch($referee, $categoryA, '2026-05-01 10:00:00');
        $this->makeDirectedMatch($referee, $categoryB, '2026-02-15 10:00:00');

        $response = $this->actingAs($user)->get(
            route('referees.show', $referee)."?tournament={$tournamentA->id}&date_from=2026-02-01&date_to=2026-02-28"
        );

        $response->assertViewHas('matches', fn ($matches) => $matches->pluck('id')->all() === [$matchAInRange->id]);
    }

    // ── Sin filtros ──────────────────────────────────────────────────────

    public function test_with_no_filters_every_directed_match_is_shown(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        ['category' => $categoryA] = $this->makeTournamentAndCategory($user, 'Copa A', 'Categoría A');
        ['category' => $categoryB] = $this->makeTournamentAndCategory($user, 'Copa B', 'Categoría B');

        $this->makeDirectedMatch($referee, $categoryA, '2026-01-01 10:00:00');
        $this->makeDirectedMatch($referee, $categoryB, '2026-06-01 10:00:00');

        $response = $this->actingAs($user)->get(route('referees.show', $referee));

        $response->assertOk()->assertViewHas('matches', fn ($matches) => $matches->count() === 2);
    }

    // ── Seguridad / ownership ────────────────────────────────────────────

    public function test_a_user_cannot_filter_another_users_referee_matches(): void
    {
        $owner = User::factory()->create();
        $referee = Referee::factory()->for($owner)->create();
        ['category' => $category] = $this->makeTournamentAndCategory($owner, 'Copa A', 'Categoría A');
        $match = $this->makeDirectedMatch($referee, $category);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get(route('referees.show', $referee)."?tournament={$match->tournament_id}")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_view_a_referees_filtered_matches(): void
    {
        $referee = Referee::factory()->create();

        $this->get(route('referees.show', $referee).'?date_from=2026-01-01')
            ->assertRedirect(route('login'));
    }
}
