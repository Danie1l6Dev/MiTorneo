<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'tournament_id' => fn (array $attributes) => Category::findOrFail((int) $attributes['category_id'])->tournament_id,
            // A 26-letter pool is small enough that two groups in the same
            // category collide fairly often (category_id + name is unique),
            // which made tests creating multiple unnamed groups flaky.
            'name' => 'Grupo '.strtoupper(fake()->randomLetter()).fake()->numberBetween(100, 999),
            'order' => 0,
        ];
    }
}
