<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskActivity>
 */
class TaskActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'type' => ActivityType::DescriptionChanged,
            'data' => null,
        ];
    }
}
