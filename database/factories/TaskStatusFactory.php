<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskStatus>
 */
class TaskStatusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->word(),
            'color' => fake()->randomElement(TaskStatus::COLORS),
            'position' => 10,
            'is_done' => false,
        ];
    }
}
