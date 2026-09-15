<?php

namespace Tests\Feature\Services;

use App\Models\Category;
use App\Models\Club;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\CategoryTournamentPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T02-01 of docs/plan-reestructuracion/02-unificacion-categorias-torneo.md.
 * The core guarantee under test: promoting a legacy category/group/team to
 * the catalog NEVER changes its id, so anything that references it by id
 * (phases, matches, results) keeps working untouched.
 */
class CategoryTournamentPromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CategoryTournamentPromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CategoryTournamentPromotionService;
    }

    public function test_dry_run_never_writes_to_the_database(): void
    {
        $tournament = Tournament::factory()->create();
        Category::factory()->for($tournament)->create(['name' => 'Baby']);

        $this->service->run(execute: false);

        $this->assertSame(0, Category::query()->whereNull('tournament_id')->count());
        $this->assertSame(0, DB::table('tournament_category')->count());
    }

    public function test_it_promotes_a_category_in_place_keeping_its_id(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create(['name' => 'Baby']);

        $this->service->run(execute: true);

        $category->refresh();
        $this->assertSame($category->id, $category->id);
        $this->assertNull($category->tournament_id);
        $this->assertSame($tournament->user_id, $category->user_id);
        $this->assertSame('BABY', $category->name);
        $this->assertTrue($tournament->globalCategories()->whereKey($category->id)->exists());
    }

    public function test_a_phase_and_its_matches_survive_promotion_unchanged(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($category)->for($tournament)->create();
        $teamA = Team::factory()->for($category)->for($tournament)->create();
        $teamB = Team::factory()->for($category)->for($tournament)->create();
        $match = TournamentMatch::factory()->for($phase, 'competitionPhase')->create([
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'home_score' => 2,
            'away_score' => 1,
        ]);

        $this->service->run(execute: true);

        $phase->refresh();
        $match->refresh();
        $this->assertSame($category->fresh()->id, $phase->category_id);
        $this->assertSame($tournament->id, $phase->tournament_id);
        $this->assertSame($teamA->id, $match->home_team_id);
        $this->assertSame($teamB->id, $match->away_team_id);
        $this->assertSame(2, $match->home_score);
        $this->assertSame(1, $match->away_score);
    }

    public function test_two_tournaments_with_the_same_category_name_are_never_merged(): void
    {
        $organizer = User::factory()->create();
        $tournamentA = Tournament::factory()->for($organizer)->create(['name' => 'Torneo A']);
        $tournamentB = Tournament::factory()->for($organizer)->create(['name' => 'Torneo B']);
        $categoryA = Category::factory()->for($tournamentA)->create(['name' => 'Infantil']);
        $categoryB = Category::factory()->for($tournamentB)->create(['name' => 'Infantil']);

        $this->service->run(execute: true);

        $categoryA->refresh();
        $categoryB->refresh();
        $this->assertNotSame($categoryA->id, $categoryB->id);
        $this->assertSame('INFANTIL', $categoryA->name);
        $this->assertSame('INFANTIL (TORNEO B)', $categoryB->name);
        $this->assertTrue($tournamentA->globalCategories()->whereKey($categoryA->id)->exists());
        $this->assertTrue($tournamentB->globalCategories()->whereKey($categoryB->id)->exists());
        $this->assertFalse($tournamentA->globalCategories()->whereKey($categoryB->id)->exists());
    }

    public function test_two_tournaments_with_the_same_category_name_in_different_case_are_still_disambiguated(): void
    {
        // Category::name is uppercased on save (NormalizesToUppercase), so
        // "Teterito" and "TETERITO" collide the moment both are promoted --
        // the disambiguation check has to compare names case-insensitively
        // or it never notices the clash before it happens.
        $organizer = User::factory()->create();
        $tournamentA = Tournament::factory()->for($organizer)->create(['name' => 'Torneo A']);
        $tournamentB = Tournament::factory()->for($organizer)->create(['name' => 'Torneo B']);
        $categoryA = Category::factory()->for($tournamentA)->create(['name' => 'Teterito']);
        $categoryB = Category::factory()->for($tournamentB)->create(['name' => 'TETERITO']);

        $this->service->run(execute: true);

        $categoryA->refresh();
        $categoryB->refresh();
        $this->assertNotSame($categoryA->id, $categoryB->id);
        $this->assertSame('TETERITO', $categoryA->name);
        $this->assertSame('TETERITO (TORNEO B)', $categoryB->name);
    }

    public function test_it_disambiguates_against_a_pre_existing_global_category(): void
    {
        $organizer = User::factory()->create();
        Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'name' => 'Infantil']);
        $tournament = Tournament::factory()->for($organizer)->create(['name' => 'Torneo X']);
        $legacy = Category::factory()->for($tournament)->create(['name' => 'Infantil']);

        $this->service->run(execute: true);

        $this->assertSame('INFANTIL (TORNEO X)', $legacy->fresh()->name);
        $this->assertSame(2, Category::query()->whereNull('tournament_id')->where('user_id', $organizer->id)->where('name', 'like', 'INFANTIL%')->count());
    }

    public function test_it_promotes_groups_in_place(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $group = Group::factory()->for($category)->for($tournament)->create();

        $this->service->run(execute: true);

        $group->refresh();
        $this->assertNull($group->tournament_id);
        $this->assertSame($category->id, $group->category_id);
    }

    public function test_it_resolves_a_club_for_each_team_and_promotes_it_in_place(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create();
        $team = Team::factory()->for($category)->for($tournament)->create(['name' => 'Nilmar']);

        $this->service->run(execute: true);

        $team->refresh();
        $this->assertNull($team->tournament_id);
        $this->assertNotNull($team->club_id);
        $club = Club::find($team->club_id);
        $this->assertSame('NILMAR', $club->name);
        $this->assertSame($tournament->user_id, $club->user_id);
        $this->assertTrue($tournament->globalTeams()->whereKey($team->id)->exists());
    }

    public function test_a_club_name_alias_resolves_the_club_without_renaming_the_squad(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create();
        $team = Team::factory()->for($category)->for($tournament)->create(['name' => 'NILMAR (A)']);

        $this->service->run(execute: true, clubNameAliases: [
            $tournament->user_id => ['NILMAR (A)' => 'NILMAR'],
        ]);

        $team->refresh();
        $this->assertSame('NILMAR (A)', $team->name);
        $this->assertSame('NILMAR', Club::find($team->club_id)->name);
    }

    public function test_two_squads_of_the_same_club_in_one_tournament_share_the_club_but_stay_separate_teams(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create();
        $teamA = Team::factory()->for($category)->for($tournament)->create(['name' => 'Nilmar (A)']);
        $teamB = Team::factory()->for($category)->for($tournament)->create(['name' => 'Nilmar (B)']);

        // Alias keys are matched against $team->name as actually stored --
        // NormalizesToUppercase already uppercased it by the time this runs,
        // so the confirmed alias table has to be keyed the same way.
        $this->service->run(execute: true, clubNameAliases: [
            $tournament->user_id => ['NILMAR (A)' => 'NILMAR', 'NILMAR (B)' => 'NILMAR'],
        ]);

        $teamA->refresh();
        $teamB->refresh();
        $this->assertSame($teamA->club_id, $teamB->club_id);
        $this->assertNotSame($teamA->id, $teamB->id);
    }

    public function test_applies_a_provided_age_range_to_a_promoted_category(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create(['name' => 'Baby']);

        // Same as the club-alias test above: ageRanges is keyed against
        // $category->name as actually stored (already uppercased).
        $this->service->run(execute: true, ageRanges: [
            $tournament->user_id => ['BABY' => ['birth_year_from' => 2020, 'birth_year_to' => 2021]],
        ]);

        $category->refresh();
        $this->assertSame(2020, $category->birth_year_from);
        $this->assertSame(2021, $category->birth_year_to);
    }

    public function test_running_it_twice_is_a_no_op_the_second_time(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create(['name' => 'Baby']);
        Team::factory()->for($category)->for($tournament)->create();

        $this->service->run(execute: true);
        $countAfterFirst = Category::query()->count() + Team::query()->count() + Club::query()->count();

        $this->service->run(execute: true);
        $countAfterSecond = Category::query()->count() + Team::query()->count() + Club::query()->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }
}
