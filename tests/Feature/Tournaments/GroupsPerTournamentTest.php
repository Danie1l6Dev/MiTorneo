<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\StatisticsPhaseScope;
use App\Models\Category;
use App\Models\Club;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\CompetitionStatisticsService;
use App\Services\StandingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A plantel's group is a fact about the plantel IN A TOURNAMENT (tournament_team.group_id),
 * not about the plantel: the same catalog category, and the same planteles, can be
 * played in two tournaments with different groups in each.
 */
class GroupsPerTournamentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One catalog category with groups, four planteles of one club, and two tournaments that both
     * include it -- each with its own "Grupo A"/"Grupo B". The planteles are split one way in the
     * first tournament (0,1 | 2,3) and a different way in the second (0,2 | 1,3).
     *
     * @return array{user: User, category: Category, teams: list<Team>, first: Tournament, second: Tournament, firstGroups: array<string, Group>, secondGroups: array<string, Group>}
     */
    private function twoTournamentsSharingACategory(): array
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => true]);
        $teams = Team::factory()->count(4)->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null])->all();

        $first = Tournament::factory()->for($user)->create();
        $second = Tournament::factory()->for($user)->create();
        $first->globalCategories()->attach($category->id);
        $second->globalCategories()->attach($category->id);

        $firstGroups = ['A' => Group::factory()->for($category)->for($first)->create(['name' => 'Grupo A']), 'B' => Group::factory()->for($category)->for($first)->create(['name' => 'Grupo B'])];
        $secondGroups = ['A' => Group::factory()->for($category)->for($second)->create(['name' => 'Grupo A']), 'B' => Group::factory()->for($category)->for($second)->create(['name' => 'Grupo B'])];

        $first->globalTeams()->attach([
            $teams[0]->id => ['group_id' => $firstGroups['A']->id], $teams[1]->id => ['group_id' => $firstGroups['A']->id],
            $teams[2]->id => ['group_id' => $firstGroups['B']->id], $teams[3]->id => ['group_id' => $firstGroups['B']->id],
        ]);
        $second->globalTeams()->attach([
            $teams[0]->id => ['group_id' => $secondGroups['A']->id], $teams[2]->id => ['group_id' => $secondGroups['A']->id],
            $teams[1]->id => ['group_id' => $secondGroups['B']->id], $teams[3]->id => ['group_id' => $secondGroups['B']->id],
        ]);

        return compact('user', 'category', 'teams', 'first', 'second', 'firstGroups', 'secondGroups');
    }

    private function finishedMatch(CompetitionPhase $phase, Group $group, Team $home, Team $away): TournamentMatch
    {
        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'group_id' => $group->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 2,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);
    }

    public function test_a_group_lists_only_the_planteles_it_holds_in_its_own_tournament(): void
    {
        $d = $this->twoTournamentsSharingACategory();

        $this->assertEqualsCanonicalizing([$d['teams'][0]->id, $d['teams'][1]->id], $d['firstGroups']['A']->teams()->pluck('teams.id')->all());
        $this->assertEqualsCanonicalizing([$d['teams'][0]->id, $d['teams'][2]->id], $d['secondGroups']['A']->teams()->pluck('teams.id')->all());

        $this->assertSame($d['firstGroups']['A']->id, $d['teams'][1]->groupIn($d['first'])?->id);
        $this->assertSame($d['secondGroups']['B']->id, $d['teams'][1]->groupIn($d['second'])?->id);
    }

    public function test_each_tournament_sees_only_its_own_groups_of_the_category(): void
    {
        $d = $this->twoTournamentsSharingACategory();

        $this->assertEqualsCanonicalizing(
            [$d['firstGroups']['A']->id, $d['firstGroups']['B']->id],
            $d['category']->groupsFor($d['first'])->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$d['secondGroups']['A']->id, $d['secondGroups']['B']->id],
            $d['category']->groupsFor($d['second'])->pluck('id')->all()
        );
    }

    public function test_the_standings_of_each_tournament_follow_that_tournaments_groups(): void
    {
        $d = $this->twoTournamentsSharingACategory();
        [$t0, $t1, $t2, $t3] = $d['teams'];

        $firstPhase = CompetitionPhase::factory()->for($d['first'])->for($d['category'])->create(['type' => CompetitionPhaseType::League]);
        $secondPhase = CompetitionPhase::factory()->for($d['second'])->for($d['category'])->create(['type' => CompetitionPhaseType::League]);

        // In the first tournament t0 and t1 are together in A; in the second t0 and t2 are.
        $this->finishedMatch($firstPhase, $d['firstGroups']['A'], $t0, $t1);
        $this->finishedMatch($secondPhase, $d['secondGroups']['A'], $t0, $t2);

        $service = app(StandingsService::class);
        $firstTables = collect($service->tablesForPhase($firstPhase->fresh()))->keyBy('label');
        $secondTables = collect($service->tablesForPhase($secondPhase->fresh()))->keyBy('label');

        $this->assertEqualsCanonicalizing([$t0->id, $t1->id], collect($firstTables['GRUPO A']['rows'])->pluck('team.id')->all());
        $this->assertEqualsCanonicalizing([$t2->id, $t3->id], collect($firstTables['GRUPO B']['rows'])->pluck('team.id')->all());
        $this->assertEqualsCanonicalizing([$t0->id, $t2->id], collect($secondTables['GRUPO A']['rows'])->pluck('team.id')->all());
        $this->assertEqualsCanonicalizing([$t1->id, $t3->id], collect($secondTables['GRUPO B']['rows'])->pluck('team.id')->all());
    }

    public function test_the_leaderboard_counts_only_the_tournament_it_is_asked_for_and_names_the_group_there(): void
    {
        $d = $this->twoTournamentsSharingACategory();
        [$t0, $t1, $t2] = $d['teams'];

        $firstPhase = CompetitionPhase::factory()->for($d['first'])->for($d['category'])->create(['type' => CompetitionPhaseType::League]);
        $secondPhase = CompetitionPhase::factory()->for($d['second'])->for($d['category'])->create(['type' => CompetitionPhaseType::League]);

        $player = Player::factory()->create(['team_id' => $t0->id]);

        // One goal in each tournament.
        foreach ([[$firstPhase, $d['firstGroups']['A'], $t1], [$secondPhase, $d['secondGroups']['A'], $t2]] as [$phase, $group, $away]) {
            $match = $this->finishedMatch($phase, $group, $t0, $away);
            MatchEvent::factory()->create(['match_id' => $match->id, 'team_id' => $t0->id, 'player_id' => $player->id, 'type' => MatchEventType::Goal]);
        }

        $service = app(CompetitionStatisticsService::class);

        $inFirst = $service->leaderboard($d['first'], $d['category'], MatchEventType::Goal, null, StatisticsPhaseScope::All);
        $this->assertSame(1, $inFirst[0]['count']);
        $this->assertSame($d['firstGroups']['A']->id, $inFirst[0]['group']?->id);

        // Filtering by the SECOND tournament's group B finds nobody (t0 is in A there)...
        $this->assertSame([], $service->leaderboard($d['second'], $d['category'], MatchEventType::Goal, $d['secondGroups']['B'], StatisticsPhaseScope::All));
        // ...and by its group A finds the player, with the one goal of that tournament.
        $inSecond = $service->leaderboard($d['second'], $d['category'], MatchEventType::Goal, $d['secondGroups']['A'], StatisticsPhaseScope::All);
        $this->assertSame(1, $inSecond[0]['count']);
    }

    public function test_enrolling_into_a_second_tournament_leaves_the_first_ones_groups_untouched(): void
    {
        $d = $this->twoTournamentsSharingACategory();

        // Move t0 to the other group in the second tournament, from the picker.
        $this->actingAs($d['user'])
            ->put(route('tournaments.global-categories.teams.update', [$d['second'], $d['category']]), [
                'team_ids' => array_map(fn (Team $team): int => $team->id, $d['teams']),
                'groups' => [
                    $d['teams'][0]->id => $d['secondGroups']['B']->id, $d['teams'][1]->id => $d['secondGroups']['B']->id,
                    $d['teams'][2]->id => $d['secondGroups']['A']->id, $d['teams'][3]->id => $d['secondGroups']['A']->id,
                ],
            ])
            ->assertRedirect();

        $this->assertSame($d['secondGroups']['B']->id, $d['teams'][0]->groupIn($d['second'])?->id);
        $this->assertSame($d['firstGroups']['A']->id, $d['teams'][0]->groupIn($d['first'])?->id);
    }

    public function test_groups_can_be_copied_from_another_tournament_without_their_planteles(): void
    {
        $d = $this->twoTournamentsSharingACategory();
        $third = Tournament::factory()->for($d['user'])->create();
        $third->globalCategories()->attach($d['category']->id);

        $this->actingAs($d['user'])
            ->get(route('tournaments.categories.show', [$third, $d['category']]))
            ->assertOk()
            ->assertSee('Copiar grupos');

        $this->actingAs($d['user'])
            ->post(route('tournaments.categories.groups.copy', [$third, $d['category']]), ['from_tournament_id' => $d['first']->id])
            ->assertRedirect(route('tournaments.categories.show', [$third, $d['category']]));

        $copied = $d['category']->groupsFor($third)->get();
        $this->assertSame(['GRUPO A', 'GRUPO B'], $copied->pluck('name')->all());
        $this->assertSame(0, $copied->first()->teams()->count());
        // The source tournament is untouched.
        $this->assertSame(2, $d['category']->groupsFor($d['first'])->count());

        // Copying again adds nothing (the names are already there).
        $this->actingAs($d['user'])
            ->post(route('tournaments.categories.groups.copy', [$third, $d['category']]), ['from_tournament_id' => $d['first']->id])
            ->assertSessionHas('error');
        $this->assertSame(2, $d['category']->groupsFor($third)->count());
    }

    public function test_groups_cannot_be_copied_from_another_organizers_tournament(): void
    {
        $d = $this->twoTournamentsSharingACategory();
        $strangers = Tournament::factory()->create();
        $mine = Tournament::factory()->for($d['user'])->create();
        $mine->globalCategories()->attach($d['category']->id);

        $this->actingAs($d['user'])
            ->post(route('tournaments.categories.groups.copy', [$mine, $d['category']]), ['from_tournament_id' => $strangers->id])
            ->assertSessionHasErrors('from_tournament_id');

        $this->assertSame(0, $d['category']->groupsFor($mine)->count());
    }

    public function test_a_group_is_taken_out_of_a_tournament_when_its_category_is_removed_from_it(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => true]);
        $mine = Tournament::factory()->for($user)->create();
        $other = Tournament::factory()->for($user)->create();
        $mine->globalCategories()->attach($category->id);
        $other->globalCategories()->attach($category->id);
        Group::factory()->for($category)->for($mine)->create(['name' => 'Grupo A']);
        $keeps = Group::factory()->for($category)->for($other)->create(['name' => 'Grupo A']);

        $this->actingAs($user)
            ->delete(route('tournaments.global-categories.destroy', [$mine, $category]))
            ->assertRedirect();

        $this->assertSame(0, $category->groupsFor($mine)->count());
        $this->assertSame([$keeps->id], $category->groupsFor($other)->pluck('id')->all());
    }

    /**
     * Nothing may read teams.group_id to know where a plantel plays: it keeps the old assignment
     * (and legacy per-tournament planteles' own) but a plantel plays in a group PER TOURNAMENT.
     */
    public function test_nothing_reads_the_group_stored_on_the_plantel_to_know_where_it_plays(): void
    {
        $offenders = [];
        // Legacy per-tournament planteles (the only ones whose own column still means something) and the migration tools.
        $allowed = ['Team.php', 'GlobalCatalogBackfillService.php', 'CategoryTournamentPromotionService.php', 'TeamController.php', 'GroupController.php', 'pages/teams/show.blade.php', 'pages/teams/_fields.blade.php'];

        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach ((new Finder)->files()->in($dir)->name('*.php') as $file) {
                if (in_array($file->getFilename(), $allowed, true) || in_array(str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()), $allowed, true)) {
                    continue;
                }

                // "$team->group", "$team->group_id", "->with('group')", "'team.group'", "team->group?->name"...
                if (preg_match('/\$(?:\w*[tT]eam\w*|row\[.team.\])->group(?:_id)?\b|[\'"](?:\w+\.)?(?:team|teams)\.group[\'"]|with\(\[?[\'"]group[\'"]/', $file->getContents(), $match)) {
                    $offenders[] = $file->getRelativePathname().' ('.$match[0].')';
                }
            }
        }

        $this->assertSame([], $offenders, 'A plantel\'s group is per tournament: use tournament_team (Team::groupIn(), tournamentGroupId(), Group::teams()).');
    }
}
