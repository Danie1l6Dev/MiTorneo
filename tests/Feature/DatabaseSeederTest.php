<?php

namespace Tests\Feature;

use App\Models\Team;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_team_has_a_squad_of_players(): void
    {
        $this->seed(DatabaseSeeder::class);

        $teams = Team::query()->withCount('players')->get();

        $this->assertGreaterThan(0, $teams->count());

        foreach ($teams as $team) {
            $this->assertGreaterThanOrEqual(14, $team->players_count, "Team [{$team->name}] has too few players.");
        }
    }

    public function test_every_seeded_team_has_an_active_coach(): void
    {
        $this->seed(DatabaseSeeder::class);

        $teams = Team::query()->get();

        $this->assertGreaterThan(0, $teams->count());

        foreach ($teams as $team) {
            $this->assertNotNull($team->coach, "Team [{$team->name}] has no active coach.");
        }
    }
}
