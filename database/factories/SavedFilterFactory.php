<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedFilter>
 */
class SavedFilterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'filters' => ['status' => 'open', 'assignee' => '', 'tag' => '', 'fields' => [], 'sort' => '', 'direction' => 'asc'],
        ];
    }

    /**
     * Visible to everybody who can see the project.
     */
    public function shared(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null]);
    }
}
