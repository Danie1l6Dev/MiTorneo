<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
use App\Models\Referee;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\DemoResetService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class DemoResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, int>
     */
    private function countsFor(User $user): array
    {
        return [
            'tournaments' => Tournament::where('user_id', $user->id)->count(),
            'categories' => Category::where('user_id', $user->id)->count(),
            'clubs' => Club::where('user_id', $user->id)->count(),
            'referees' => Referee::where('user_id', $user->id)->count(),
            'catalogTeams' => Team::whereIn('category_id', Category::where('user_id', $user->id)->select('id'))->count(),
            'enrolledTeams' => DB::table('tournament_team')->whereIn('tournament_id', Tournament::where('user_id', $user->id)->select('id'))->count(),
            'enrolledCategories' => DB::table('tournament_category')->whereIn('tournament_id', Tournament::where('user_id', $user->id)->select('id'))->count(),
            'players' => Player::whereIn('team_id', Team::whereIn('category_id', Category::where('user_id', $user->id)->select('id'))->select('id'))->count(),
            'matches' => TournamentMatch::whereIn('tournament_id', Tournament::where('user_id', $user->id)->select('id'))->count(),
        ];
    }

    public function test_reset_only_touches_the_demo_users_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $daniel = User::where('email', 'daniel@mitorneo.test')->firstOrFail();
        $danielBefore = $this->countsFor($daniel);
        $totalUsers = User::count();
        // The seed must land in the organizer's global catalog, not as legacy
        // per-tournament rows the current UI doesn't show.
        foreach (['categories', 'clubs', 'referees', 'catalogTeams', 'enrolledTeams', 'enrolledCategories', 'players', 'matches'] as $key) {
            $this->assertGreaterThan(0, $danielBefore[$key], "Seeded account has no {$key}.");
        }
        $this->assertSame(0, Category::whereNotNull('tournament_id')->count());
        $this->assertSame(0, Team::whereNotNull('tournament_id')->count());

        // The demo visitor trashes their own data...
        $demo = User::where('email', User::DEMO_EMAIL)->firstOrFail();
        Tournament::where('user_id', $demo->id)->first()->update(['name' => 'Vandalizado']);
        Team::whereIn('tournament_id', Tournament::where('user_id', $demo->id)->select('id'))->limit(3)->delete();

        (new DemoResetService)->reset();

        // ...and it comes back clean, with the demo account itself kept.
        $this->assertSame($demo->id, User::where('email', User::DEMO_EMAIL)->value('id'));
        $this->assertDatabaseMissing('tournaments', ['name' => 'Vandalizado']);
        // (squad sizes are random, 14-18 per team, so players isn't compared exactly)
        $demoAfter = $this->countsFor($demo);
        $this->assertGreaterThan(0, $demoAfter['players']);
        unset($demoAfter['players']);
        $expected = $danielBefore;
        unset($expected['players']);
        $this->assertSame($expected, $demoAfter, 'Demo should match a fresh seed.');

        // Nobody else was touched.
        $this->assertSame($danielBefore, $this->countsFor($daniel));
        $this->assertSame($totalUsers, User::count());
    }

    public function test_reset_creates_the_demo_user_when_missing_without_touching_others(): void
    {
        $other = User::factory()->create();
        $tournament = Tournament::factory()->for($other)->create();

        (new DemoResetService)->reset();

        $this->assertDatabaseHas('users', ['email' => User::DEMO_EMAIL]);
        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id]);
    }

    public function test_reset_locks_down_a_pre_existing_demo_account(): void
    {
        // Like production: the demo user came from an old `db:seed`, with the
        // factory's public password and (hypothetically) too many privileges.
        $old = User::factory()->create(['email' => User::DEMO_EMAIL, 'role' => UserRole::Admin]);
        $this->assertTrue(Hash::check('password', $old->password));

        (new DemoResetService)->reset();

        $demo = User::where('email', User::DEMO_EMAIL)->firstOrFail();
        $this->assertSame($old->id, $demo->id);
        $this->assertFalse(Hash::check('password', $demo->password));
        $this->assertSame(UserRole::User, $demo->role);
    }

    public function test_reset_command_refuses_to_run_unless_demo_is_enabled(): void
    {
        config(['demo.enabled' => false]);
        $tournament = Tournament::factory()->for(User::factory()->create(['email' => User::DEMO_EMAIL]))->create();

        $this->artisan('demo:reset')->assertFailed();

        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id]);
    }

    public function test_reset_command_runs(): void
    {
        config(['demo.enabled' => true]);

        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertGreaterThan(0, Tournament::count());
    }

    public function test_purge_refuses_a_non_demo_account(): void
    {
        $service = new DemoResetService;
        $method = new \ReflectionMethod($service, 'purge');

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($service, User::factory()->create());
    }

    /**
     * Faudis' account holds the real production data. Whatever else changes,
     * a demo reset must leave every row he owns exactly as it was.
     */
    public function test_reset_never_touches_faudis_real_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $faudi = User::factory()->create(['email' => 'faudisp@uniguajira.edu.co']);
        $tournament = Tournament::factory()->for($faudi)->create(['name' => 'LIGA REAL LIFUTGUA']);
        $category = Category::factory()->for($tournament)->create(['name' => 'SUB-15 REAL']);
        $category->forceFill(['user_id' => $faudi->id, 'tournament_id' => null])->save();
        $tournament->globalCategories()->attach($category->id);
        $club = Club::factory()->create(['user_id' => $faudi->id, 'name' => 'CLUB REAL']);
        $referee = Referee::factory()->create(['user_id' => $faudi->id]);

        $before = $this->countsFor($faudi);

        (new DemoResetService)->reset();

        $this->assertSame($before, $this->countsFor($faudi));
        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id, 'name' => 'LIGA REAL LIFUTGUA']);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'user_id' => $faudi->id]);
        $this->assertDatabaseHas('tournament_category', ['tournament_id' => $tournament->id, 'category_id' => $category->id]);
        $this->assertDatabaseHas('clubs', ['id' => $club->id, 'name' => 'CLUB REAL']);
        $this->assertDatabaseHas('referees', ['id' => $referee->id]);
    }
}
