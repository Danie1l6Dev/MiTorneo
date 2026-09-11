<?php

namespace Database\Seeders;

use App\Enums\CategoryStatus;
use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Enums\TournamentStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\LeagueSchedule;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Referee;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\LeagueScheduleService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Standalone seeder for a tournament with two 8-team groups, each with its
 * own full single round-robin calendar (28 matches) already generated and
 * finished with results -- large enough groups to exercise real standings
 * (and "Definir clasificados" into a knockout bracket) without having to
 * play a league out by hand.
 *
 * Runs on its own, on top of whatever is already in the database, rather
 * than through DatabaseSeeder: `php artisan db:seed --class=TwoGroupLeagueSeeder`.
 * Reuses Daniel's existing user/referees when DatabaseSeeder already ran;
 * creates its own otherwise. The tournament's slug is generated through
 * Tournament::generateUniqueSlug(), so running this more than once just adds
 * another tournament instead of colliding on a duplicate slug.
 */
class TwoGroupLeagueSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'daniel@mitorneo.test'],
            ['name' => 'Daniel'],
        );

        $referees = $this->referees($user);

        $tournament = Tournament::factory()->for($user)->create([
            'name' => 'Torneo Clausura 2026',
            'slug' => Tournament::generateUniqueSlug('Torneo Clausura 2026'),
            'season' => '2026',
            'status' => TournamentStatus::Active,
        ]);

        $mayores = $tournament->categories()->create([
            'name' => 'Mayores',
            'status' => CategoryStatus::Active,
            'uses_groups' => true,
            'order' => 0,
        ]);

        $phase = $mayores->competitionPhases()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Liga',
            'type' => CompetitionPhaseType::League,
            'order' => 0,
        ]);

        $groupA = $mayores->groups()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Grupo A',
            'order' => 0,
        ]);

        $groupB = $mayores->groups()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Grupo B',
            'order' => 1,
        ]);

        $teamsA = collect([
            'Real Norte FC', 'Deportivo Sur', 'Atlético Central', 'Unión Este',
            'Estrella del Pacífico', 'Halcones United', 'Titanes FC', 'Rayo Andino',
        ])->map(fn (string $name): Team => $this->createTeam($mayores, $tournament, $name, $groupA));

        $teamsB = collect([
            'Tigres del Valle', 'Águilas Doradas', 'Leones del Norte', 'Panteras FC',
            'Cóndores Azules', 'Vikingos FC', 'Cometas del Sur', 'Guerreros Andinos',
        ])->map(fn (string $name): Team => $this->createTeam($mayores, $tournament, $name, $groupB));

        $this->generateFinishedSchedule($phase, $teamsA, $groupA, $referees);
        $this->generateFinishedSchedule($phase, $teamsB, $groupB, $referees);

        $this->command?->info("Torneo '{$tournament->name}' creado (slug: {$tournament->slug}) con 2 grupos de 8 equipos, calendarios completos y resultados cargados.");
    }

    /**
     * @return Collection<int, Referee>
     */
    private function referees(User $user): Collection
    {
        $existing = $user->referees()->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        return collect([
            ['full_name' => 'Roberto Fernández', 'document_number' => '30111222'],
            ['full_name' => 'Marta Gómez', 'document_number' => '30111333'],
            ['full_name' => 'Luis Herrera', 'document_number' => '30111444'],
            ['full_name' => 'Patricia Núñez', 'document_number' => '30111555'],
        ])->map(fn (array $attributes): Referee => $user->referees()->create($attributes));
    }

    private function createTeam(Category $category, Tournament $tournament, string $name, Group $group): Team
    {
        $team = $category->teams()->forceCreate([
            'tournament_id' => $tournament->id,
            'group_id' => $group->id,
            'name' => $name,
        ]);

        $squadSize = random_int(14, 18);

        for ($jerseyNumber = 1; $jerseyNumber <= $squadSize; $jerseyNumber++) {
            $team->players()->create([
                'full_name' => fake()->name(),
                'document_number' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
                'jersey_number' => $jerseyNumber,
            ]);
        }

        $team->coaches()->create([
            'full_name' => fake()->name(),
            'document_number' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
        ]);

        return $team;
    }

    /**
     * Generates one group's full single round-robin schedule (using the
     * same service the app itself uses) and immediately marks every fixture
     * as finished with a random scoreline plus matching goal/assist
     * MatchEvent rows -- so standings, "Eventos del partido" and any
     * goleadores/asistencias leaderboards all have real data right away.
     *
     * @param  Collection<int, Team>  $teams
     * @param  Collection<int, Referee>  $referees  Cycled across fixtures; every 5th fixture is deliberately
     *                                              left without one, so "Sin árbitro asignado" also has real matches to show.
     */
    private function generateFinishedSchedule(CompetitionPhase $phase, Collection $teams, Group $group, Collection $referees): void
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
                $homeScore = random_int(0, 4);
                $awayScore = random_int(0, 4);

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

                if ($referees->isNotEmpty() && $fixtureIndex % 5 !== 4) {
                    $match->referee_id = $referees[$fixtureIndex % $referees->count()]->id;
                }

                $match->save();

                $this->seedGoalEvents($match, $match->home_team_id, $homeScore);
                $this->seedGoalEvents($match, $match->away_team_id, $awayScore);

                $fixtureIndex++;
            }
        }
    }

    /**
     * One goal MatchEvent per goal in the scoreline, each scored by a
     * random squad player -- and, for roughly half of them, an assist from
     * a DIFFERENT teammate (keeping it within MatchEventRequest's "can't
     * out-assist your teammates' goals" rule).
     */
    private function seedGoalEvents(TournamentMatch $match, int $teamId, int $goalCount): void
    {
        if ($goalCount === 0) {
            return;
        }

        $players = Player::query()->where('team_id', $teamId)->get();

        if ($players->isEmpty()) {
            return;
        }

        for ($i = 0; $i < $goalCount; $i++) {
            $scorer = $players->random();

            MatchEvent::query()->create([
                'match_id' => $match->id,
                'team_id' => $teamId,
                'player_id' => $scorer->id,
                'type' => MatchEventType::Goal,
            ]);

            if ($players->count() > 1 && random_int(1, 100) <= 50) {
                $assister = $players->where('id', '!=', $scorer->id)->random();

                MatchEvent::query()->create([
                    'match_id' => $match->id,
                    'team_id' => $teamId,
                    'player_id' => $assister->id,
                    'type' => MatchEventType::Assist,
                ]);
            }
        }
    }
}
