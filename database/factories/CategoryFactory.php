<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tournament_id' => Tournament::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'order' => 0,
        ];
    }
}
