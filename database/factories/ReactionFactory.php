<?php

namespace Database\Factories;

use App\Models\Reaction;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reaction>
 */
class ReactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reactable_type' => Task::class,
            'reactable_id' => Task::factory(),
            'emoji' => '👍',
        ];
    }
}
