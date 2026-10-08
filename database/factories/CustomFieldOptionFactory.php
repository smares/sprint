<?php

namespace Database\Factories;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomFieldOption>
 */
class CustomFieldOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'custom_field_id' => CustomField::factory()->state(['type' => CustomFieldType::Select]),
            'name' => fake()->unique()->word(),
            'color' => '#71717a',
            'position' => 10,
        ];
    }
}
