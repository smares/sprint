<?php

namespace Database\Factories;

use App\CustomFieldType;
use App\Models\CustomField;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function select(): static
    {
        return $this->state(['type' => CustomFieldType::Select]);
    }

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => CustomFieldType::Text,
            'position' => 10,
            'show_in_list' => true,
        ];
    }
}
