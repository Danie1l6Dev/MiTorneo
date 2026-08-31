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
            'name' => 'Grupo '.fake()->randomLetter(),
            'order' => 0,
        ];
    }
}
