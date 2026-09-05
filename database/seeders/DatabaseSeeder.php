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

        // Demo tournaments so there's always something ready to click through
        // right after logging in, covering the states that are otherwise
        // tedious to set up by hand: one waiting on "Generar calendario", one
        // already finished with a small group-stage draw ready to try, and
        // one with a full 8-team league already played out end to end so the
        // knockout bracket (cuartos -> semifinal -> final) can be tried too.
        $this->seedReadyForScheduleTournament($daniel);
        $this->seedReadyForDrawTournament($daniel);
        $this->seedReadyForKnockoutBracketTournament($daniel);
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
        $this->generateFinishedSchedule($phase, $teamsA, [[2, 1], [0, 0], [3, 1]], $groupA);
        $this->generateFinishedSchedule($phase, $teamsB, [[1, 1], [2, 0], [1, 2]], $groupB);
    }

    /**
     * A third tournament with a single, ungrouped 8-team league whose full
     * single round-robin calendar (28 matches) is already generated and
     * finished with random scores -- large enough to be immediately ready
     * for "Definir clasificados" -> Eliminación directa with all 8 teams,
     * exercising the full cuartos -> semifinal -> final bracket cascade
     * without first having to play a league out by hand.
     */
    private function seedReadyForKnockoutBracketTournament(User $user): void
    {
        $tournament = Tournament::factory()->for($user)->create([
            'name' => 'Liga Profesional 2026',
            'season' => '2026',
            'status' => TournamentStatus::Active,
        ]);

        $primera = $tournament->categories()->create([
            'name' => 'Primera División',
            'status' => CategoryStatus::Active,
            'uses_groups' => false,
            'order' => 0,
        ]);

        $phase = $primera->competitionPhases()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Liga',
            'type' => CompetitionPhaseType::League,
            'order' => 0,
        ]);

        $teams = collect([
            'Real Norte FC', 'Deportivo Sur', 'Atlético Central', 'Unión Este',
            'Estrella del Pacífico', 'Halcones United', 'Titanes FC', 'Rayo Andino',
        ])->map(fn (string $name): Team => $this->createTeam($primera, $tournament, $name));

        $this->generateFinishedSchedule($phase, $teams);
    }

    private function createTeam(Category $category, Tournament $tournament, string $name, ?Group $group = null): Team
    {
        $team = $category->teams()->forceCreate([
            'tournament_id' => $tournament->id,
            'group_id' => $group?->id,
            'name' => $name,
        ]);

        $this->seedPlayersForTeam($team);
        $this->seedCoachForTeam($team);

        return $team;
    }

    /**
     * A realistic-sized squad (14-18 players) with sequential jersey numbers
     * (1..N, guaranteed unique within the team) so every team this seeder
     * creates is immediately ready to explore the roster feature, instead of
     * registering players by hand. Jersey numbers are assigned directly
     * rather than via PlayerFactory's own random draw: that draw is unique
     * per Faker instance across the *whole* seeder run, and would exhaust
     * its 1-99 pool well before all ~20 teams are seeded.
     */
    private function seedPlayersForTeam(Team $team): void
    {
        $squadSize = random_int(14, 18);

        for ($jerseyNumber = 1; $jerseyNumber <= $squadSize; $jerseyNumber++) {
            $team->players()->create([
                'full_name' => fake()->name(),
                'document_number' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
                'jersey_number' => $jerseyNumber,
            ]);
        }
    }

    /**
     * One active head coach per team, so the DT card/quick-add row is
     * immediately populated instead of showing "No registrado" everywhere.
     */
    private function seedCoachForTeam(Team $team): void
    {
        $team->coaches()->create([
            'full_name' => fake()->name(),
            'document_number' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
        ]);
    }

    /**
     * Generate a single round-robin schedule (using the same service the app
     * itself uses), optionally scoped to one group, and immediately mark
     * every fixture as finished. Explicit scores can be given in generation
     * order; any fixture beyond the given list (or all of them, if none are
     * given at all) gets a random scoreline instead.
     *
     * @param  Collection<int, Team>  $teams
     * @param  array<int, array{0: int, 1: int}>  $scores
     */
    private function generateFinishedSchedule(CompetitionPhase $phase, Collection $teams, array $scores = [], ?Group $group = null): void
    {
        $schedule = new LeagueSchedule;
        $schedule->tournament_id = $phase->tournament_id;
        $schedule->competition_phase_id = $phase->id;
        $schedule->group_id = $group?->id;
        $schedule->format = ScheduleFormat::SingleRound;
        $schedule->generated_at = now();
        $schedule->save();

        $fixtureIndex = 0;

        foreach (app(LeagueScheduleService::class)->generate($teams, ScheduleFormat::SingleRound) as $round) {
            foreach ($round['fixtures'] as $fixture) {
                [$homeScore, $awayScore] = $scores[$fixtureIndex] ?? [random_int(0, 4), random_int(0, 4)];
                $fixtureIndex++;

                $match = new TournamentMatch;
                $match->tournament_id = $phase->tournament_id;
                $match->category_id = $phase->category_id;
                $match->competition_phase_id = $phase->id;
                $match->group_id = $group?->id;
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
