<?php

namespace Database\Seeders;

use App\Enums\CategoryStatus;
use App\Enums\CompetitionPhaseType;
use App\Enums\TournamentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Group;
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

    private function createTeam(Category $category, Tournament $tournament, string $name, ?Group $group = null): Team
    {
        return $category->teams()->forceCreate([
            'tournament_id' => $tournament->id,
            'group_id' => $group?->id,
            'name' => $name,
        ]);
    }
}
