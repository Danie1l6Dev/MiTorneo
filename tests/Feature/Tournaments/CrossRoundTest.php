<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\DrawMethod;
use App\Enums\MatchStatus;
use App\Enums\PairingScope;
use App\Http\Requests\AdvancePhaseRequest;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\CrossRoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CrossRoundTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tournament $tournament;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tournament = Tournament::factory()->for($this->user)->create();
        $this->category = Category::factory()->for($this->tournament)->create(['uses_groups' => true]);
    }

    private function leaguePhase(): CompetitionPhase
    {
        return CompetitionPhase::factory()->for($this->tournament)->for($this->category)->create([
            'name' => 'Fase de Liga',
            'type' => CompetitionPhaseType::League,
            'order' => 0,
        ]);
    }

    /**
     * A finished group where the first team beats everyone below it, the
     * second beats everyone below it, and so on: its standings come out in
     * the exact order the teams are returned in.
     *
     * @return Collection<int, Team>
     */
    private function rankedGroup(CompetitionPhase $phase, string $name, int $size): Collection
    {
        $group = Group::factory()->for($this->tournament)->for($this->category)->create(['name' => $name]);
        $teams = Team::factory()->for($this->tournament)->for($this->category)->for($group)->count($size)->create()->values();

        foreach ($teams as $i => $stronger) {
            foreach ($teams->slice($i + 1) as $weaker) {
                TournamentMatch::factory()->for($phase)->create([
                    'tournament_id' => $this->tournament->id,
                    'category_id' => $this->category->id,
                    'group_id' => $group->id,
                    'home_team_id' => $stronger->id,
                    'away_team_id' => $weaker->id,
                    'home_score' => 1,
                    'away_score' => 0,
                    'status' => MatchStatus::Finished,
                ]);
            }
        }

        return $teams;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function advanceFromLeague(CompetitionPhase $phase, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('phases.advance.store', $phase), array_merge([
            'name' => 'Cruces',
            'type' => AdvancePhaseRequest::CROSS_ROUND,
            'draw_method' => DrawMethod::Seeded->value,
        ], $overrides));
    }

    /**
     * @param  array<int, int>  $pairs  [home team id, away team id, home goals, away goals]
     */
    private function crossRoundPhase(array $pairs, array $directTeams = [], bool $finished = true): CompetitionPhase
    {
        $phase = CompetitionPhase::factory()->for($this->tournament)->for($this->category)->create([
            'name' => 'Cruces',
            'type' => CompetitionPhaseType::Knockout,
            'cross_round' => true,
            'order' => 1,
        ]);

        $phase->teams()->attach(array_merge(
            collect($pairs)->flatMap(fn (array $pair): array => [$pair[0], $pair[1]])->all(),
            $directTeams,
        ));

        foreach ($pairs as [$home, $away, $homeGoals, $awayGoals]) {
            TournamentMatch::factory()->for($phase)->create([
                'tournament_id' => $this->tournament->id,
                'category_id' => $this->category->id,
                'round_number' => 1,
                'home_team_id' => $home,
                'away_team_id' => $away,
                'home_score' => $finished ? $homeGoals : null,
                'away_score' => $finished ? $awayGoals : null,
                'status' => $finished ? MatchStatus::Finished : MatchStatus::Scheduled,
            ]);
        }

        return $phase;
    }

    public function test_two_groups_are_crossed_by_position(): void
    {
        $phase = $this->leaguePhase();
        $a = $this->rankedGroup($phase, 'Grupo A', 4);
        $b = $this->rankedGroup($phase, 'Grupo B', 4);

        $response = $this->advanceFromLeague($phase, ['qualifiers' => [4, 4]]);

        $newPhase = CompetitionPhase::where('name', 'CRUCES')->firstOrFail();
        $response->assertRedirect(route('phases.show', $newPhase));

        $this->assertTrue($newPhase->cross_round);
        $this->assertSame(CompetitionPhaseType::Knockout, $newPhase->type);
        $this->assertSame(1, $newPhase->order);
        $this->assertSame(8, $newPhase->teams()->count());

        $matches = $newPhase->matches()->orderBy('id')->get();
        $this->assertCount(4, $matches);
        $this->assertTrue($matches->every(fn (TournamentMatch $match): bool => $match->round_number === 1));

        $this->assertSame(
            [[$a[0]->id, $b[3]->id], [$a[1]->id, $b[2]->id], [$a[2]->id, $b[1]->id], [$a[3]->id, $b[0]->id]],
            $matches->map(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id])->all(),
        );
    }

    public function test_teams_that_pass_directly_skip_the_crosses_and_a_lone_group_pairs_within_itself(): void
    {
        $phase = $this->leaguePhase();
        $a = $this->rankedGroup($phase, 'Grupo A', 6);
        $b = $this->rankedGroup($phase, 'Grupo B', 8);

        // Group A: its best 4 qualify and all pass directly. Group B: 7
        // qualify (the 8th is out) and its 1st passes directly, so 2nd-7th
        // are left to play each other: 2 vs 7, 3 vs 6, 4 vs 5.
        $this->advanceFromLeague($phase, ['qualifiers' => [4, 7], 'direct' => [4, 1]])->assertSessionHasNoErrors();

        $newPhase = CompetitionPhase::where('name', 'CRUCES')->firstOrFail();

        $this->assertSame(11, $newPhase->teams()->count());

        $matches = $newPhase->matches()->orderBy('id')->get();
        $this->assertSame(
            [[$b[1]->id, $b[6]->id], [$b[2]->id, $b[5]->id], [$b[3]->id, $b[4]->id]],
            $matches->map(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id])->all(),
        );

        $outcome = app(CrossRoundService::class)->outcome($newPhase);
        $this->assertEqualsCanonicalizing(
            [$a[0]->id, $a[1]->id, $a[2]->id, $a[3]->id, $b[0]->id],
            $outcome['direct']->pluck('id')->all(),
        );
    }

    public function test_an_odd_number_of_teams_playing_the_round_is_rejected(): void
    {
        $phase = $this->leaguePhase();
        $this->rankedGroup($phase, 'Grupo A', 4);
        $this->rankedGroup($phase, 'Grupo B', 4);

        $this->advanceFromLeague($phase, ['qualifiers' => [3, 4]])->assertSessionHasErrors('qualifiers');

        $this->assertSame(0, CompetitionPhase::where('name', 'CRUCES')->count());
    }

    public function test_crossing_two_groups_needs_the_same_number_of_playing_teams_in_each(): void
    {
        $phase = $this->leaguePhase();
        $this->rankedGroup($phase, 'Grupo A', 4);
        $this->rankedGroup($phase, 'Grupo B', 4);

        $this->advanceFromLeague($phase, ['qualifiers' => [4, 4], 'direct' => [2, 0]])->assertSessionHasErrors('qualifiers');
    }

    public function test_the_cross_round_does_not_need_a_power_of_two_of_qualifiers(): void
    {
        $phase = $this->leaguePhase();
        $this->rankedGroup($phase, 'Grupo A', 3);
        $this->rankedGroup($phase, 'Grupo B', 3);

        $this->advanceFromLeague($phase, ['qualifiers' => [3, 3]])->assertSessionHasNoErrors();

        $this->assertSame(3, CompetitionPhase::where('name', 'CRUCES')->firstOrFail()->matches()->count());
    }

    public function test_qualifiers_per_table_is_refused_for_a_cross_round(): void
    {
        $phase = $this->leaguePhase();
        $this->rankedGroup($phase, 'Grupo A', 3);
        $this->rankedGroup($phase, 'Grupo B', 3);

        $this->advanceFromLeague($phase, ['qualifiers' => [3, 3], 'qualifiers_per_table' => 3])->assertSessionHasErrors('qualifiers_per_table');
    }

    public function test_a_cut_league_can_be_crossed_without_playing_every_match(): void
    {
        $phase = $this->leaguePhase();
        $a = $this->rankedGroup($phase, 'Grupo A', 3);
        $this->rankedGroup($phase, 'Grupo B', 3);

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $this->tournament->id,
            'category_id' => $this->category->id,
            'home_team_id' => $a[0]->id,
            'away_team_id' => $a[1]->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->advanceFromLeague($phase, ['qualifiers' => [3, 3]])->assertSessionHas('error');

        $this->actingAs($this->user)->post(route('phases.cut.store', $phase));
        $this->advanceFromLeague($phase, ['qualifiers' => [3, 3]])->assertSessionHasNoErrors();

        $this->assertSame(3, CompetitionPhase::where('name', 'CRUCES')->firstOrFail()->matches()->count());
    }

    public function test_the_outcome_ranks_losers_by_how_narrow_their_defeat_was(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(8)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 3, 0],  // loser: -3
            [$teams[2]->id, $teams[3]->id, 2, 1],  // loser: -1, 1 goal
            [$teams[4]->id, $teams[5]->id, 4, 3],  // loser: -1, 3 goals
            [$teams[6]->id, $teams[7]->id, 0, 2],  // away wins, loser: -2
        ]);

        $outcome = app(CrossRoundService::class)->outcome($phase);

        $this->assertSame(0, $outcome['undecided']);
        $this->assertSame(
            [$teams[0]->id, $teams[2]->id, $teams[4]->id, $teams[7]->id],
            $outcome['winners']->pluck('id')->all(),
        );
        $this->assertSame(
            [$teams[5]->id, $teams[3]->id, $teams[6]->id, $teams[1]->id],
            collect($outcome['losers'])->pluck('team.id')->all(),
        );
    }

    public function test_losers_level_on_goals_are_ranked_by_fair_play(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(4)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 1, 0],  // loser: team 1
            [$teams[2]->id, $teams[3]->id, 1, 0],  // loser: team 3
        ]);

        // Both losers: -1 goal difference, 0 goals for. Team 1 was shown a
        // red card, so team 3 ranks as the better loser.
        $redCardMatch = $phase->matches()->orderBy('id')->first();
        \App\Models\MatchEvent::factory()->create([
            'match_id' => $redCardMatch->id,
            'team_id' => $teams[1]->id,
            'type' => \App\Enums\MatchEventType::RedCard,
        ]);

        $outcome = app(CrossRoundService::class)->outcome($phase);

        $this->assertSame([$teams[3]->id, $teams[1]->id], collect($outcome['losers'])->pluck('team.id')->all());
        $this->assertSame([0, 4], collect($outcome['losers'])->pluck('fair_play')->all());
    }

    public function test_winners_and_the_best_loser_are_drawn_into_a_knockout_bracket(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(6)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 3, 0],
            [$teams[2]->id, $teams[3]->id, 2, 1],
            [$teams[4]->id, $teams[5]->id, 1, 0],
        ]);

        $response = $this->actingAs($this->user)->post(route('phases.advance-cross.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'best_losers' => 1,
            'draw_method' => DrawMethod::Random->value,
            'pairing_scope' => PairingScope::Anyone->value,
        ]);

        $newPhase = CompetitionPhase::where('name', 'SEMIFINALES')->firstOrFail();
        $response->assertRedirect(route('phases.show', $newPhase));

        $this->assertFalse($newPhase->cross_round);
        $this->assertSame(2, $newPhase->order);

        // Winners 0, 2, 4 plus the best loser (3: lost 2-1, having scored a
        // goal, over 5, who lost 1-0 without scoring).
        $this->assertEqualsCanonicalizing(
            [$teams[0]->id, $teams[2]->id, $teams[4]->id, $teams[3]->id],
            $newPhase->teams()->pluck('teams.id')->all(),
        );

        // Full bracket: 2 semifinals + the final.
        $this->assertSame(3, $newPhase->matches()->count());
    }

    public function test_teams_that_passed_directly_are_part_of_the_next_draw(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(6)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 1, 0],
            [$teams[2]->id, $teams[3]->id, 1, 0],
        ], [$teams[4]->id, $teams[5]->id]);

        $this->actingAs($this->user)->post(route('phases.advance-cross.store', $phase), [
            'name' => 'Cruces 2',
            'type' => AdvancePhaseRequest::CROSS_ROUND,
            'best_losers' => 0,
            'draw_method' => DrawMethod::Random->value,
            'pairing_scope' => PairingScope::Anyone->value,
        ]);

        $newPhase = CompetitionPhase::where('name', 'CRUCES 2')->firstOrFail();

        $this->assertTrue($newPhase->cross_round);
        $this->assertSame(4, $newPhase->teams()->count());
        $this->assertSame(2, $newPhase->matches()->count());
    }

    /**
     * A finished cross round (4 crosses, so 4 winners) that came from two
     * groups of 4: A1-A4 and B1-B4. Group A's four teams win their crosses
     * (by 1-0) except where noted, so the winners are always a mix.
     *
     * @return array{0: CompetitionPhase, 1: Collection<int, Team>, 2: Collection<int, Team>}
     */
    private function crossRoundFromTwoGroups(): array
    {
        $league = $this->leaguePhase();
        $a = $this->rankedGroup($league, 'Grupo A', 4);
        $b = $this->rankedGroup($league, 'Grupo B', 4);

        // A1, A2, B1 and B2 win: two teams of each group are left.
        $phase = $this->crossRoundPhase([
            [$a[0]->id, $b[3]->id, 1, 0],
            [$a[1]->id, $b[2]->id, 1, 0],
            [$b[0]->id, $a[3]->id, 1, 0],
            [$b[1]->id, $a[2]->id, 1, 0],
        ]);

        return [$phase, $a, $b];
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function nextPairs(CompetitionPhase $crossRound, DrawMethod $method, PairingScope $scope): array
    {
        $this->actingAs($this->user)->post(route('phases.advance-cross.store', $crossRound), [
            'name' => 'Siguiente',
            'type' => AdvancePhaseRequest::CROSS_ROUND,
            'best_losers' => 0,
            'draw_method' => $method->value,
            'pairing_scope' => $scope->value,
        ])->assertSessionHasNoErrors();

        return CompetitionPhase::where('name', 'SIGUIENTE')->firstOrFail()->matches()->orderBy('id')->get()
            ->map(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id])
            ->all();
    }

    public function test_the_next_draw_by_position_between_groups_crosses_the_best_with_the_worst_of_another_group(): void
    {
        [$phase, $a, $b] = $this->crossRoundFromTwoGroups();

        // Winners: A1, A2, B1, B2 (positions 1, 2, 1, 2).
        $pairs = $this->nextPairs($phase, DrawMethod::Seeded, PairingScope::BetweenGroups);

        $this->assertSame([[$a[0]->id, $b[1]->id], [$b[0]->id, $a[1]->id]], $pairs);
    }

    public function test_the_next_draw_by_position_across_everyone_pairs_best_with_worst_ignoring_groups(): void
    {
        [$phase, $a, $b] = $this->crossRoundFromTwoGroups();

        // Ordered by position, then group: A1, B1, A2, B2 -> A1 vs B2, B1 vs A2.
        $pairs = $this->nextPairs($phase, DrawMethod::Seeded, PairingScope::Anyone);

        $this->assertSame([[$a[0]->id, $b[1]->id], [$b[0]->id, $a[1]->id]], $pairs);
    }

    public function test_a_random_next_draw_between_groups_never_pairs_teams_of_the_same_group(): void
    {
        [$phase, $a, $b] = $this->crossRoundFromTwoGroups();
        $groupA = [$a[0]->id, $a[1]->id];

        $pairs = $this->nextPairs($phase, DrawMethod::Random, PairingScope::BetweenGroups);

        $this->assertCount(2, $pairs);

        foreach ($pairs as $pair) {
            $this->assertCount(1, array_intersect($pair, $groupA));
        }
    }

    public function test_a_next_draw_between_groups_is_refused_when_only_one_group_is_left(): void
    {
        $league = $this->leaguePhase();
        $a = $this->rankedGroup($league, 'Grupo A', 4);
        $this->rankedGroup($league, 'Grupo B', 4);

        $phase = $this->crossRoundPhase([
            [$a[0]->id, $a[3]->id, 1, 0],
            [$a[1]->id, $a[2]->id, 1, 0],
        ]);

        $this->actingAs($this->user)->post(route('phases.advance-cross.store', $phase), [
            'name' => 'Siguiente',
            'type' => AdvancePhaseRequest::CROSS_ROUND,
            'best_losers' => 0,
            'draw_method' => DrawMethod::Random->value,
            'pairing_scope' => PairingScope::BetweenGroups->value,
        ])->assertSessionHasErrors('pairing_scope');
    }

    public function test_the_league_cross_round_can_be_drawn_at_random_between_groups(): void
    {
        $phase = $this->leaguePhase();
        $a = $this->rankedGroup($phase, 'Grupo A', 4);
        $this->rankedGroup($phase, 'Grupo B', 4);
        $groupA = $a->pluck('id')->all();

        $this->advanceFromLeague($phase, [
            'qualifiers' => [4, 4],
            'draw_method' => DrawMethod::Random->value,
            'pairing_scope' => PairingScope::BetweenGroups->value,
        ])->assertSessionHasNoErrors();

        $matches = CompetitionPhase::where('name', 'CRUCES')->firstOrFail()->matches()->get();

        $this->assertCount(4, $matches);

        foreach ($matches as $match) {
            $this->assertCount(1, array_intersect([$match->home_team_id, $match->away_team_id], $groupA));
        }
    }

    public function test_the_league_cross_round_can_be_seeded_across_everyone(): void
    {
        $phase = $this->leaguePhase();
        $a = $this->rankedGroup($phase, 'Grupo A', 2);
        $b = $this->rankedGroup($phase, 'Grupo B', 2);

        $this->advanceFromLeague($phase, [
            'qualifiers' => [2, 2],
            'pairing_scope' => PairingScope::Anyone->value,
        ])->assertSessionHasNoErrors();

        // By position then group: A1, B1, A2, B2 -> A1 vs B2, B1 vs A2.
        $this->assertSame(
            [[$a[0]->id, $b[1]->id], [$b[0]->id, $a[1]->id]],
            CompetitionPhase::where('name', 'CRUCES')->firstOrFail()->matches()->orderBy('id')->get()
                ->map(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id])->all(),
        );
    }

    public function test_the_total_of_qualifiers_must_fit_the_chosen_next_phase(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(6)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 1, 0],
            [$teams[2]->id, $teams[3]->id, 1, 0],
            [$teams[4]->id, $teams[5]->id, 1, 0],
        ]);

        // 3 winners: not a power of two for a bracket, and odd for another cross round.
        foreach ([CompetitionPhaseType::Knockout->value, AdvancePhaseRequest::CROSS_ROUND, CompetitionPhaseType::Semifinal->value] as $type) {
            $this->actingAs($this->user)->post(route('phases.advance-cross.store', $phase), [
                'name' => 'Siguiente',
                'type' => $type,
                'best_losers' => 0,
                'draw_method' => DrawMethod::Random->value,
                'pairing_scope' => PairingScope::Anyone->value,
            ])->assertSessionHasErrors('best_losers');
        }

        $this->assertSame(0, CompetitionPhase::where('name', 'SIGUIENTE')->count());

        // Asking for more best losers than there are is refused too.
        $this->actingAs($this->user)->post(route('phases.advance-cross.store', $phase), [
            'name' => 'Siguiente',
            'type' => CompetitionPhaseType::Knockout->value,
            'best_losers' => 4,
            'draw_method' => DrawMethod::Random->value,
            'pairing_scope' => PairingScope::Anyone->value,
        ])->assertSessionHasErrors('best_losers');
    }

    public function test_a_cross_round_with_unplayed_crosses_cannot_be_advanced(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(4)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 1, 0],
            [$teams[2]->id, $teams[3]->id, 1, 0],
        ], finished: false);

        $this->actingAs($this->user)->get(route('phases.advance-cross.create', $phase))
            ->assertRedirect(route('phases.show', $phase));
    }

    public function test_the_advance_form_and_the_phase_page_render_for_a_finished_cross_round(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(4)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 1, 0],
            [$teams[2]->id, $teams[3]->id, 2, 0],
        ]);

        $this->actingAs($this->user)->get(route('phases.show', $phase))
            ->assertOk()
            ->assertSee('Ronda de cruces')
            ->assertSee(route('phases.advance-cross.create', $phase));

        $this->actingAs($this->user)->get(route('phases.advance-cross.create', $phase))
            ->assertOk()
            ->assertSee('Resultado de la ronda de cruces');
    }

    public function test_every_cross_of_a_round_is_shown_not_just_the_first_of_each_half(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(6)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 1, 0],
            [$teams[2]->id, $teams[3]->id, 1, 0],
            [$teams[4]->id, $teams[5]->id, 1, 0],
        ]);

        $response = $this->actingAs($this->user)->get(route('phases.show', $phase))->assertOk()->assertSee('Cruces de la ronda');

        foreach ($teams as $team) {
            $response->assertSee($team->fresh()->name);
        }

        // Never laid out as a two-sided bracket, which would only draw some crosses.
        $response->assertDontSee('lg:block');
    }

    public function test_the_cross_round_is_laid_out_in_one_column_per_group_with_the_teams_that_passed_directly(): void
    {
        $league = $this->leaguePhase();
        $a = $this->rankedGroup($league, 'Grupo A', 4);
        $b = $this->rankedGroup($league, 'Grupo B', 4);

        // Group B plays 2 vs 3 among itself; group A passes directly; one
        // cross mixes both groups.
        $phase = $this->crossRoundPhase([
            [$b[1]->id, $b[2]->id, 1, 0],
            [$a[0]->id, $b[3]->id, 1, 0],
        ], [$a[1]->id, $b[0]->id]);

        $board = app(CrossRoundService::class)->columns($phase);

        $this->assertCount(2, $board['columns']);
        [$columnA, $columnB] = $board['columns'];

        $this->assertSame('GRUPO A', $columnA['label']);
        $this->assertSame([$a[1]->id], $columnA['direct']->pluck('id')->all());
        $this->assertCount(0, $columnA['matches']);

        $this->assertSame('GRUPO B', $columnB['label']);
        $this->assertSame([$b[0]->id], $columnB['direct']->pluck('id')->all());
        $this->assertSame([$b[1]->id], $columnB['matches']->pluck('home_team_id')->all());

        $this->assertCount(1, $board['between']);

        $page = $this->actingAs($this->user)->get(route('phases.show', $phase))
            ->assertOk()
            ->assertSee('GRUPO A')
            ->assertSee('GRUPO B')
            ->assertSee('Pasan directo a la siguiente fase')
            ->assertSee('Cruces entre grupos');

        foreach ([$a[1], $b[0], $b[1], $b[2]] as $team) {
            $page->assertSee($team->fresh()->name);
        }
    }

    public function test_the_public_portal_shows_the_cross_round_by_group_too(): void
    {
        $league = $this->leaguePhase();
        $b = $this->rankedGroup($league, 'Grupo B', 4);

        $phase = $this->crossRoundPhase([[$b[1]->id, $b[2]->id, 1, 0]], [$b[0]->id]);
        $this->tournament->forceFill(['slug' => 'torneo-publico'])->save();

        $this->get(route('public.tournaments.phases.show', [$this->tournament, $phase]))
            ->assertOk()
            ->assertSee('GRUPO B')
            ->assertSee($b[0]->fresh()->name)
            ->assertSee('Pasan directo a la siguiente fase');
    }

    public function test_a_cross_decided_on_penalties_or_extra_time_says_so_on_its_card(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(4)->create()->values();
        $phase = $this->crossRoundPhase([
            [$teams[0]->id, $teams[1]->id, 1, 1],
            [$teams[2]->id, $teams[3]->id, 1, 1],
        ]);

        [$byPenalties, $byExtraTime] = $phase->matches()->orderBy('id')->get()->all();
        $byPenalties->update(['home_penalty_score' => 4, 'away_penalty_score' => 3]);
        $byExtraTime->update(['home_extra_time_score' => 0, 'away_extra_time_score' => 1]);

        $this->actingAs($this->user)->get(route('phases.show', $phase))
            ->assertOk()
            // The bracket's own card: the shoot-out tally next to each score, and
            // a note on how the tie was settled.
            ->assertSee('(4)', false)
            ->assertSee('(3)', false)
            ->assertSee('Definido por penales (4-3)')
            ->assertSee('Resultado incluye la prórroga');
    }

    public function test_the_league_advance_form_offers_the_cross_round(): void
    {
        $phase = $this->leaguePhase();
        $this->rankedGroup($phase, 'Grupo A', 3);
        $this->rankedGroup($phase, 'Grupo B', 3);

        $this->actingAs($this->user)->get(route('phases.advance.create', $phase))
            ->assertOk()
            ->assertSee('Ronda de cruces entre grupos')
            ->assertSee('GRUPO A');
    }

    public function test_a_cross_round_is_never_shown_as_having_a_champion(): void
    {
        $teams = Team::factory()->for($this->tournament)->for($this->category)->count(2)->create()->values();
        $phase = $this->crossRoundPhase([[$teams[0]->id, $teams[1]->id, 1, 0]]);

        $this->actingAs($this->user)->get(route('phases.show', $phase))->assertOk()->assertDontSee('Campeón');
    }
}
