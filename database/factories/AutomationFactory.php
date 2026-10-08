<?php

namespace Database\Factories;

use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
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
            'created_by' => User::factory(),
            'name' => fake()->words(3, true),
            'trigger' => AutomationTrigger::AssigneeChanged,
            'trigger_value' => null,
            'conditions' => null,
            'actions' => [['type' => AutomationAction::Comment->value, 'value' => 'Hello']],
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
