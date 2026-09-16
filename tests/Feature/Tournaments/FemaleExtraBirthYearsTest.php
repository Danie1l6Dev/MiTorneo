<?php

namespace Tests\Feature\Tournaments;

use App\Enums\Gender;
use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category::$female_extra_birth_years lets a mixed category admit female
 * players a few years older than its plain birth_year_to cutoff allows for
 * everyone else -- see Player::ageEligibleForCategory().
 */
class FemaleExtraBirthYearsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_male_player_is_judged_by_the_plain_birth_year_to_cutoff(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $user->id,
            'birth_year_to' => 2015,
            'female_extra_birth_years' => 2,
        ]);

        $player = Player::factory()->male()->create(['birth_date' => '2013-01-01']);

        $this->assertFalse($player->ageEligibleForCategory($category));
    }

    public function test_a_female_player_gets_the_extra_years_allowance(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $user->id,
            'birth_year_to' => 2015,
            'female_extra_birth_years' => 2,
        ]);

        // Born 2013: 2 years older than the plain cutoff (2015) allows,
        // exactly what the 2-year female allowance covers.
        $player = Player::factory()->female()->create(['birth_date' => '2013-01-01']);

        $this->assertTrue($player->ageEligibleForCategory($category));
    }

    public function test_a_female_player_still_older_than_the_allowance_covers_is_ineligible(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $user->id,
            'birth_year_to' => 2015,
            'female_extra_birth_years' => 2,
        ]);

        $player = Player::factory()->female()->create(['birth_date' => '2012-01-01']);

        $this->assertFalse($player->ageEligibleForCategory($category));
    }

    public function test_a_player_with_no_gender_on_file_is_judged_by_the_plain_cutoff(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $user->id,
            'birth_year_to' => 2015,
            'female_extra_birth_years' => 2,
        ]);

        $player = Player::factory()->create(['birth_date' => '2013-01-01', 'gender' => null]);

        $this->assertFalse($player->ageEligibleForCategory($category));
    }

    public function test_the_club_enrollment_flow_honors_the_female_allowance(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $user->id,
            'birth_year_to' => 2015,
            'female_extra_birth_years' => 2,
        ]);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);

        $response = $this->actingAs($user)->post(route('clubs.players.store', $club), [
            'full_name' => 'Jugadora Grande',
            'birth_date' => '2013-01-01',
            'gender' => Gender::Female->value,
            'team_ids' => [$team->id],
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('players', [
            'full_name' => 'JUGADORA GRANDE',
            'team_id' => $team->id,
            'gender' => Gender::Female->value,
        ]);
    }
}
