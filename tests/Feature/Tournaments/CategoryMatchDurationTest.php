<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How long a match of a category takes -- what the scheduler uses to space
 * kickoffs and to detect two matches overlapping on one cancha.
 */
class CategoryMatchDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_category_without_its_own_duration_uses_the_default(): void
    {
        $category = Category::factory()->create(['match_duration_minutes' => null]);

        $this->assertSame(Category::DEFAULT_MATCH_DURATION_MINUTES, $category->matchDurationMinutes());
        $this->assertSame(60, $category->matchDurationMinutes());
    }

    public function test_a_global_category_can_be_created_with_its_own_duration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('categories.store'), ['name' => 'Baby', 'status' => 'active', 'uses_groups' => false, 'match_duration_minutes' => 20])
            ->assertSessionHasNoErrors();

        $category = Category::query()->where('user_id', $user->id)->where('name', 'BABY')->firstOrFail();

        $this->assertSame(20, $category->match_duration_minutes);
        $this->assertSame(20, $category->matchDurationMinutes());
    }

    public function test_the_duration_can_be_cleared_to_go_back_to_the_default(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'match_duration_minutes' => 30]);

        $this->actingAs($user)
            ->put(route('categories.update', $category), ['name' => $category->name, 'status' => 'active', 'uses_groups' => false, 'match_duration_minutes' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($category->fresh()->match_duration_minutes);
    }

    public function test_an_absurd_duration_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('categories.store'), ['name' => 'Baby', 'status' => 'active', 'uses_groups' => false, 'match_duration_minutes' => 1])
            ->assertSessionHasErrors('match_duration_minutes');

        $this->actingAs($user)
            ->post(route('categories.store'), ['name' => 'Baby', 'status' => 'active', 'uses_groups' => false, 'match_duration_minutes' => 999])
            ->assertSessionHasErrors('match_duration_minutes');
    }

    public function test_the_category_form_offers_the_duration_field(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('categories.create'))
            ->assertOk()
            ->assertSee('Duración de cada partido');
    }
}
