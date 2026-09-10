<?php

namespace Database\Seeders;

use App\Enums\CategoryStatus;
use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\SanctionStatus;
use App\Enums\ScheduleFormat;
use App\Enums\TournamentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\LeagueSchedule;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Referee;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\LeagueScheduleService;
use App\Services\SanctionService;
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

        // Global referees, created once for Daniel and reused across every
        // finished match seeded below -- spanning more than one tournament,
        // so the referee list/detail pages have real data (match counts,
        // match history) to show immediately instead of an empty state.
        $referees = $this->seedReferees($daniel);

        // Demo tournaments so there's always something ready to click through
        // right after logging in, covering the states that are otherwise
        // tedious to set up by hand: one waiting on "Generar calendario", one
        // already finished with a small group-stage draw ready to try, and
        // one with a full 8-team league already played out end to end so the
        // knockout bracket (cuartos -> semifinal -> final) can be tried too.
        $this->seedReadyForScheduleTournament($daniel);
        $this->seedReadyForDrawTournament($daniel, $referees);
        $this->seedReadyForKnockoutBracketTournament($daniel, $referees);
    }

    /**
     * @return Collection<int, Referee>
     */
    private function seedReferees(User $user): Collection
    {
        return collect([
            ['full_name' => 'Roberto Fernández', 'document_number' => '30111222'],
            ['full_name' => 'Marta Gómez', 'document_number' => '30111333'],
            ['full_name' => 'Luis Herrera', 'document_number' => '30111444'],
            ['full_name' => 'Patricia Núñez', 'document_number' => '30111555'],
        ])->map(fn (array $attributes): Referee => $user->referees()->create($attributes));
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
     *
     * @param  Collection<int, Referee>  $referees
     */
    private function seedReadyForDrawTournament(User $user, Collection $referees): void
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
        $this->generateFinishedSchedule($phase, $teamsA, [[2, 1], [0, 0], [3, 1]], $groupA, $referees);
        $this->generateFinishedSchedule($phase, $teamsB, [[1, 1], [2, 0], [1, 2]], $groupB, $referees);
    }

    /**
     * A third tournament with a single, ungrouped 8-team league whose full
     * single round-robin calendar (28 matches) is already generated and
     * finished with random scores -- large enough to be immediately ready
     * for "Definir clasificados" -> Eliminación directa with all 8 teams,
     * exercising the full cuartos -> semifinal -> final bracket cascade
     * without first having to play a league out by hand.
     *
     * @param  Collection<int, Referee>  $referees
     */
    private function seedReadyForKnockoutBracketTournament(User $user, Collection $referees): void
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

        $matches = $this->generateFinishedSchedule($phase, $teams, referees: $referees);

        // Layered on top of the goals/assists every finished match already
        // got -- one guaranteed example of every sanction state the
        // feature supports, so the Sanciones section has real, varied data
        // immediately instead of depending on the random scores/lineup
        // above happening to produce one.
        $this->seedSanctionScenarios($matches);
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
     * given at all) gets a random scoreline instead. Each finished match
     * also gets goal/assist MatchEvent rows matching its own scoreline (see
     * seedGoalEvents()), so the "Eventos del partido" section and any
     * goleadores/asistencias leaderboards have real data everywhere, not
     * just wherever a scenario below happens to add cards by hand.
     *
     * @param  Collection<int, Team>  $teams
     * @param  array<int, array{0: int, 1: int}>  $scores
     * @param  Collection<int, Referee>|null  $referees  Cycled across fixtures when given; every 5th fixture is
     *                                                   deliberately left without one, so "Sin árbitro asignado" also has real matches to show.
     * @return Collection<int, TournamentMatch> Every match just created, in generation order -- lets a caller
     *                                          (see seedSanctionScenarios()) target a specific round_number afterward.
     */
    private function generateFinishedSchedule(CompetitionPhase $phase, Collection $teams, array $scores = [], ?Group $group = null, ?Collection $referees = null): Collection
    {
        $schedule = new LeagueSchedule;
        $schedule->tournament_id = $phase->tournament_id;
        $schedule->competition_phase_id = $phase->id;
        $schedule->group_id = $group?->id;
        $schedule->format = ScheduleFormat::SingleRound;
        $schedule->generated_at = now();
        $schedule->save();

        $fixtureIndex = 0;
        $matches = collect();

        foreach (app(LeagueScheduleService::class)->generate($teams, ScheduleFormat::SingleRound) as $round) {
            foreach ($round['fixtures'] as $fixture) {
                [$homeScore, $awayScore] = $scores[$fixtureIndex] ?? [random_int(0, 4), random_int(0, 4)];

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

                if ($referees !== null && $referees->isNotEmpty() && $fixtureIndex % 5 !== 4) {
                    $match->referee_id = $referees[$fixtureIndex % $referees->count()]->id;
                }

                $match->save();

                $this->seedGoalEvents($match, $match->home_team_id, $homeScore);
                $this->seedGoalEvents($match, $match->away_team_id, $awayScore);

                $matches->push($match);
                $fixtureIndex++;
            }
        }

        return $matches;
    }

    /**
     * One goal MatchEvent per goal in the scoreline, each scored by a
     * random squad player -- and, for roughly half of them, an assist from
     * a DIFFERENT teammate. Tying each assist to a specific goal (even
     * though the row itself doesn't record that link) is what keeps this
     * naturally within MatchEventRequest's "can't out-assist your
     * teammates' goals" rule, without having to duplicate that math here.
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

    /**
     * Layers one guaranteed example of every sanction state the feature
     * supports on top of the goals/assists every match already got --
     * picked by round_number (reliably set by LeagueScheduleService, and
     * what Sanction::teamMatchSequence() itself sorts by), so how many
     * fechas end up already "served" by the end of seeding is deliberate,
     * not left to chance:
     *
     * - Round 1: resolved for 1 fecha, with 6 later rounds already
     *   finished -- long since "Sanción cumplida".
     * - Round 5: resolved for 3 fechas, with only rounds 6-7 left to serve
     *   them in -- still actively suspended when seeding ends.
     * - Round 7 (the last): left Pending -- no committee decision yet.
     * - Round 3: a DT sent off, resolved with fechas AND a fine.
     * - Round 2: the same player shown two separate yellows -- auto
     *   -resolved as a double_yellow, no committee step at all.
     *
     * @param  Collection<int, TournamentMatch>  $matches
     */
    private function seedSanctionScenarios(Collection $matches): void
    {
        $sanctions = app(SanctionService::class);

        $this->seedRedCardSanction($matches->firstWhere('round_number', 1), $sanctions, matchesBanned: 1, resolutionNotes: 'Agresión a un rival tras el pitazo final.');
        $this->seedRedCardSanction($matches->firstWhere('round_number', 5), $sanctions, matchesBanned: 3, resolutionNotes: 'Reacción violenta tras una falta cobrada en contra.', useAwayTeam: true);
        $this->seedRedCardSanction($matches->firstWhere('round_number', 7), $sanctions, matchesBanned: null, useAwayTeam: true);
        $this->seedCoachRedCardSanction($matches->firstWhere('round_number', 3), $sanctions);
        $this->seedDoubleYellowSanction($matches->firstWhere('round_number', 2), $sanctions);
    }

    private function seedRedCardSanction(?TournamentMatch $match, SanctionService $sanctions, ?int $matchesBanned, ?string $resolutionNotes = null, bool $useAwayTeam = false): void
    {
        if ($match === null) {
            return;
        }

        $teamId = $useAwayTeam ? $match->away_team_id : $match->home_team_id;
        $player = Player::query()->where('team_id', $teamId)->inRandomOrder()->first();

        if ($player === null) {
            return;
        }

        $subject = ['team_id' => $teamId, 'player_id' => $player->id, 'coach_id' => null];

        MatchEvent::query()->create([...$subject, 'match_id' => $match->id, 'type' => MatchEventType::RedCard]);
        $sanctions->syncForSubject($match, $subject);

        if ($matchesBanned === null) {
            return;
        }

        Sanction::query()->where('match_id', $match->id)->where('player_id', $player->id)->first()?->update([
            'status' => SanctionStatus::Resolved,
            'matches_banned' => $matchesBanned,
            'resolution_notes' => $resolutionNotes,
            'resolved_at' => now(),
        ]);
    }

    private function seedCoachRedCardSanction(?TournamentMatch $match, SanctionService $sanctions): void
    {
        if ($match === null) {
            return;
        }

        $coach = Coach::query()->where('team_id', $match->home_team_id)->first();

        if ($coach === null) {
            return;
        }

        $subject = ['team_id' => $match->home_team_id, 'player_id' => null, 'coach_id' => $coach->id];

        MatchEvent::query()->create([...$subject, 'match_id' => $match->id, 'type' => MatchEventType::RedCard]);
        $sanctions->syncForSubject($match, $subject);

        Sanction::query()->where('match_id', $match->id)->where('coach_id', $coach->id)->first()?->update([
            'status' => SanctionStatus::Resolved,
            'matches_banned' => 2,
            'fine_amount' => 25000,
            'resolution_notes' => 'Conducta antideportiva hacia el árbitro.',
            'resolved_at' => now(),
        ]);
    }

    private function seedDoubleYellowSanction(?TournamentMatch $match, SanctionService $sanctions): void
    {
        if ($match === null) {
            return;
        }

        $player = Player::query()->where('team_id', $match->away_team_id)->inRandomOrder()->first();

        if ($player === null) {
            return;
        }

        $subject = ['team_id' => $match->away_team_id, 'player_id' => $player->id, 'coach_id' => null];

        MatchEvent::query()->create([...$subject, 'match_id' => $match->id, 'type' => MatchEventType::YellowCard]);
        MatchEvent::query()->create([...$subject, 'match_id' => $match->id, 'type' => MatchEventType::YellowCard]);
        $sanctions->syncForSubject($match, $subject);
    }
}
