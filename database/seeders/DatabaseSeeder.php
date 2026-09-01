<?php

namespace Database\Seeders;

use App\Enums\CategoryStatus;
use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Enums\TournamentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\LeagueSchedule;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\LeagueScheduleService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@mitorneo.test',
            'role' => UserRole::Admin,
        ]);

        $daniel = User::factory()->create([
            'name' => 'Daniel',
            'email' => 'daniel@mitorneo.test',
        ]);

        User::factory()->create([
            'name' => 'Usuario Demo',
            'email' => 'demo@mitorneo.test',
        ]);

        // Two demo tournaments so there's always something ready to click
        // through right after logging in, covering the two states that are
        // otherwise tedious to set up by hand: one waiting on "Generar
        // calendario", and one already finished and waiting on "Definir
        // clasificados" (the knockout draw).
        $this->seedReadyForScheduleTournament($daniel);
        $this->seedReadyForDrawTournament($daniel);
    }

    /**
     * A tournament with its category, groups and teams fully set up, and a
     * league phase created but with no schedule generated yet -- ready to
     * exercise "Generar calendario" from a clean state.
     */
    private function seedReadyForScheduleTournament(User $user): void
    {
        $tournament = Tournament::factory()->for($user)->create([
            'name' => 'Campeonato Municipal 2026',
            'season' => '2026',
            'status' => TournamentStatus::Active,
        ]);

        $teterito = $tournament->categories()->create([
            'name' => 'Teterito',
            'status' => CategoryStatus::Active,
            'uses_groups' => true,
            'order' => 0,
        ]);

        $juvenil = $tournament->categories()->create([
            'name' => 'Juvenil',
            'status' => CategoryStatus::Active,
            'uses_groups' => false,
            'order' => 1,
        ]);

        $teterito->competitionPhases()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Liga',
            'type' => CompetitionPhaseType::League,
            'order' => 0,
        ]);

        $groupA = $teterito->groups()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Grupo A',
            'order' => 0,
        ]);

        $groupB = $teterito->groups()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Grupo B',
            'order' => 1,
        ]);

        $this->createTeam($teterito, $tournament, 'Real Norte', $groupA);
        $this->createTeam($teterito, $tournament, 'Deportivo Sur', $groupA);
        $this->createTeam($teterito, $tournament, 'Atlético Centro', $groupB);
        $this->createTeam($teterito, $tournament, 'Unión Este', $groupB);

        $this->createTeam($juvenil, $tournament, 'Juvenil A');
        $this->createTeam($juvenil, $tournament, 'Juvenil B');
    }

    /**
     * A second tournament whose league phase is already fully scheduled and
     * finished, with real (varied) results, so its standings are ready and
     * "Definir clasificados" can be used immediately to try the live
     * knockout draw without first having to play out a whole league phase.
     */
    private function seedReadyForDrawTournament(User $user): void
    {
        $tournament = Tournament::factory()->for($user)->create([
            'name' => 'Copa Relámpago 2026',
            'season' => '2026',
            'status' => TournamentStatus::Active,
        ]);

        $subquince = $tournament->categories()->create([
            'name' => 'Sub-15',
            'status' => CategoryStatus::Active,
            'uses_groups' => true,
            'order' => 0,
        ]);

        $phase = $subquince->competitionPhases()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Liga',
            'type' => CompetitionPhaseType::League,
            'order' => 0,
        ]);

        $groupA = $subquince->groups()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Grupo A',
            'order' => 0,
        ]);

        $groupB = $subquince->groups()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Grupo B',
            'order' => 1,
        ]);

        $teamsA = collect([
            $this->createTeam($subquince, $tournament, 'Halcones FC', $groupA),
            $this->createTeam($subquince, $tournament, 'Tigres del Valle', $groupA),
            $this->createTeam($subquince, $tournament, 'Águilas Doradas', $groupA),
        ]);

        $teamsB = collect([
            $this->createTeam($subquince, $tournament, 'Leones del Norte', $groupB),
            $this->createTeam($subquince, $tournament, 'Panteras FC', $groupB),
            $this->createTeam($subquince, $tournament, 'Cóndores Azules', $groupB),
        ]);

        // Deliberately varied scores (wins, a draw, different margins) so the
        // standings tables have a clear, non-trivial ranking to look at.
        $this->generateFinishedGroupSchedule($phase, $groupA, $teamsA, [[2, 1], [0, 0], [3, 1]]);
        $this->generateFinishedGroupSchedule($phase, $groupB, $teamsB, [[1, 1], [2, 0], [1, 2]]);
    }

    private function createTeam(Category $category, Tournament $tournament, string $name, ?Group $group = null): Team
    {
        return $category->teams()->forceCreate([
            'tournament_id' => $tournament->id,
            'group_id' => $group?->id,
            'name' => $name,
        ]);
    }

    /**
     * Generate a single round-robin schedule for a group (using the same
     * service the app itself uses) and immediately mark every fixture as
     * finished with the given scores, in generation order.
     *
     * @param  Collection<int, Team>  $teams
     * @param  array<int, array{0: int, 1: int}>  $scores
     */
    private function generateFinishedGroupSchedule(CompetitionPhase $phase, Group $group, Collection $teams, array $scores): void
    {
        $schedule = new LeagueSchedule;
        $schedule->tournament_id = $phase->tournament_id;
        $schedule->competition_phase_id = $phase->id;
        $schedule->group_id = $group->id;
        $schedule->format = ScheduleFormat::SingleRound;
        $schedule->generated_at = now();
        $schedule->save();

        $fixtureIndex = 0;

        foreach (app(LeagueScheduleService::class)->generate($teams, ScheduleFormat::SingleRound) as $round) {
            foreach ($round['fixtures'] as $fixture) {
                [$homeScore, $awayScore] = $scores[$fixtureIndex] ?? [1, 1];
                $fixtureIndex++;

                $match = new TournamentMatch;
                $match->tournament_id = $phase->tournament_id;
                $match->category_id = $phase->category_id;
                $match->competition_phase_id = $phase->id;
                $match->group_id = $group->id;
                $match->league_schedule_id = $schedule->id;
                $match->home_team_id = $fixture['home_team_id'];
                $match->away_team_id = $fixture['away_team_id'];
                $match->round_number = $round['round_number'];
                $match->home_score = $homeScore;
                $match->away_score = $awayScore;
                $match->status = MatchStatus::Finished;
                $match->save();
            }
        }
    }
}
