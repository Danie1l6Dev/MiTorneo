<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Models\TiebreakDraw;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\StandingsService;
use App\Services\TiebreakDrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TiebreakDrawTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CompetitionPhase $phase;

    /** @var array<int, Team> */
    private array $teams;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $tournament = Tournament::factory()->for($this->user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $this->phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 0]);
        $this->teams = Team::factory()->for($tournament)->for($category)->count(3)->create()->all();
        $this->phase->teams()->attach(collect($this->teams)->pluck('id'));

        // A and B draw 1-1 and both beat C by the same score: A and B are level
        // on everything, C is clearly third.
        $this->play($this->teams[0], $this->teams[1], 1, 1);
        $this->play($this->teams[0], $this->teams[2], 2, 0);
        $this->play($this->teams[1], $this->teams[2], 2, 0);
    }

    private function play(Team $home, Team $away, int $homeScore, int $awayScore): void
    {
        TournamentMatch::factory()->for($this->phase)->create([
            'tournament_id' => $this->phase->tournament_id,
            'category_id' => $this->phase->category_id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'status' => MatchStatus::Finished,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return app(StandingsService::class)->tablesForPhase($this->phase->fresh())[0]['rows'];
    }

    public function test_teams_level_on_everything_are_marked_as_a_pending_tie(): void
    {
        $rows = $this->rows();

        $this->assertSame('pending', $rows[0]['tie']);
        $this->assertSame('pending', $rows[1]['tie']);
        $this->assertNull($rows[2]['tie']);
        $this->assertEqualsCanonicalizing([$this->teams[0]->id, $this->teams[1]->id], $rows[0]['tie_teams']);
    }

    public function test_a_draw_is_stored_and_keeps_the_order_whenever_the_table_is_recalculated(): void
    {
        $service = app(TiebreakDrawService::class);

        $this->assertTrue($service->draw($this->phase, [$this->teams[0]->id, $this->teams[1]->id]));

        $first = array_map(fn (array $row): int => $row['team']->id, $this->rows());

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, array_map(fn (array $row): int => $row['team']->id, $this->rows()));
        }

        $this->assertSame('drawn', $this->rows()[0]['tie']);
        $this->assertSame($this->teams[2]->id, $first[2]);
    }

    public function test_only_an_existing_tie_can_be_drawn(): void
    {
        $service = app(TiebreakDrawService::class);

        $this->assertFalse($service->draw($this->phase, [$this->teams[0]->id, $this->teams[2]->id]));
        $this->assertFalse($service->draw($this->phase, [$this->teams[0]->id]));
        $this->assertSame(0, TiebreakDraw::query()->count());
    }

    public function test_a_draw_is_ignored_once_the_tie_it_settled_no_longer_exists(): void
    {
        app(TiebreakDrawService::class)->draw($this->phase, [$this->teams[0]->id, $this->teams[1]->id]);

        // A new result breaks the tie: A is now clearly ahead of B.
        $this->play($this->teams[0], $this->teams[1], 3, 0);

        $rows = $this->rows();
        $this->assertSame($this->teams[0]->id, $rows[0]['team']->id);
        $this->assertNull($rows[0]['tie']);
        $this->assertNull($rows[1]['tie']);
    }

    public function test_an_annulled_draw_leaves_the_tie_pending_again(): void
    {
        $service = app(TiebreakDrawService::class);
        $service->draw($this->phase, [$this->teams[0]->id, $this->teams[1]->id]);

        $service->annul($this->phase, $this->rows()[0]['draw_group']);

        $this->assertSame('pending', $this->rows()[0]['tie']);
    }

    public function test_the_draw_button_stores_the_order_and_the_public_page_shows_only_badges(): void
    {
        $this->actingAs($this->user)
            ->post(route('phases.tiebreak-draw.store', $this->phase), ['team_ids' => [$this->teams[0]->id, $this->teams[1]->id]])
            ->assertSessionHas('status');

        $this->assertSame(2, TiebreakDraw::query()->count());

        $this->actingAs($this->user)->get(route('phases.show', $this->phase))
            ->assertOk()
            ->assertSee('Por sorteo')
            ->assertSee('Anular');
    }

    public function test_another_user_cannot_draw(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('phases.tiebreak-draw.store', $this->phase), ['team_ids' => [$this->teams[0]->id, $this->teams[1]->id]])
            ->assertForbidden();

        $this->assertSame(0, TiebreakDraw::query()->count());
    }

    public function test_the_table_offers_the_draw_while_the_tie_is_pending(): void
    {
        $this->actingAs($this->user)->get(route('phases.show', $this->phase))
            ->assertOk()
            ->assertSee('Empate total')
            ->assertSee('Sortear');
    }

    public function test_advancing_is_refused_while_a_pending_tie_straddles_the_qualification_line(): void
    {
        $response = $this->actingAs($this->user)->post(route('phases.advance.store', $this->phase), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 1,
        ]);

        $response->assertSessionHasErrors('qualifiers_per_table');
        $this->assertStringContainsString('sorteo', session('errors')->first('qualifiers_per_table'));
    }

    public function test_the_tie_warning_goes_away_once_the_tie_has_been_drawn(): void
    {
        app(TiebreakDrawService::class)->draw($this->phase, [$this->teams[0]->id, $this->teams[1]->id]);

        $this->actingAs($this->user)->post(route('phases.advance.store', $this->phase), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 1,
        ]);

        // Whatever else the request does, it no longer stops over the tie.
        $this->assertStringNotContainsString('sorteo', (string) optional(session('errors'))->first('qualifiers_per_table'));
    }
}
