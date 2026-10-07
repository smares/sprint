<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function done(): static
    {
        return $this->afterMaking(fn (Task $task) => $task->status_id = $task->project->doneStatus()->id);
    }

    public function inProgress(): static
    {
        return $this->afterMaking(fn (Task $task) => $task->status_id = $task->project->statuses()->skip(1)->firstOrFail()->id);
    }

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'creator_id' => User::factory(),
            'assignee_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'due_date' => null,
        ];
    }
}
