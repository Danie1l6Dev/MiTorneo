<?php

namespace Database\Seeders;

use App\Enums\CompetitionPhaseType;
use App\Enums\TournamentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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

        $this->seedDemoTournament($daniel);
    }

    /**
     * Seed a sample tournament with a group stage so there is something to
     * look at right after logging in. All test users share the "password" password.
     */
    private function seedDemoTournament(User $user): void
    {
        $tournament = Tournament::factory()->for($user)->create([
            'name' => 'Campeonato Municipal 2026',
            'season' => '2026',
            'status' => TournamentStatus::Active,
        ]);

        $teterito = $tournament->categories()->create(['name' => 'Teterito', 'order' => 0]);
        $tournament->categories()->create(['name' => 'Juvenil', 'order' => 1]);

        $liga = $teterito->competitionPhases()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Liga',
            'type' => CompetitionPhaseType::League,
            'order' => 0,
        ]);

        $groupA = $liga->groups()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Grupo A',
            'order' => 0,
        ]);

        $groupB = $liga->groups()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => 'Grupo B',
            'order' => 1,
        ]);

        $teams = collect(['Real Norte', 'Deportivo Sur', 'Atlético Centro', 'Unión Este'])
            ->map(fn (string $name) => $this->createTeam($teterito, $tournament, $name));

        $groupA->teams()->attach($teams->take(2));
        $groupB->teams()->attach($teams->skip(2));
    }

    private function createTeam(Category $category, Tournament $tournament, string $name): Team
    {
        return $category->teams()->forceCreate([
            'tournament_id' => $tournament->id,
            'name' => $name,
        ]);
    }
}
