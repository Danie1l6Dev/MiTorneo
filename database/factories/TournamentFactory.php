<?php

namespace Database\Factories;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tournament>
 */
class TournamentFactory extends Factory
{
    protected $model = Tournament::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => ucfirst(fake()->word()).' '.ucfirst(fake()->word()).' '.fake()->year(),
            // Independent of 'name' on purpose: a factory-definition closure
            // sees definition()'s own defaults, not a caller's override array
            // (e.g. DatabaseSeeder's explicit ['name' => '...']), so deriving
            // this from 'name' here could silently mismatch it. A random
            // slug is all tests need; DatabaseSeeder sets a readable one
            // explicitly alongside its own explicit 'name' overrides.
            'slug' => Str::slug(fake()->unique()->words(4, true)),
            'description' => fake()->optional()->sentence(),
            'season' => (string) fake()->year(),
            'status' => TournamentStatus::Draft,
        ];
    }
}
