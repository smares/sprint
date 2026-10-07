<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\TaskStatus;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with demo data (local development only).
     */
    public function run(): void
    {
        $users = collect(['Anna Beispiel', 'Ben Muster', 'Clara Test'])->map(
            fn (string $name) => User::factory()->create([
                'name' => $name,
                'email' => str($name)->before(' ')->lower().'@example.com',
                'password' => 'password',
            ])
        );

        Project::factory(2)->create()->each(function (Project $project) use ($users) {
            Task::factory(5)->for($project)->create([
                'creator_id' => $users->first()->id,
                'assignee_id' => fn () => $users->random()->id,
                'status' => fn () => fake()->randomElement(TaskStatus::cases()),
                'due_date' => fn () => fake()->optional(0.7)->dateTimeBetween('-3 days', '+3 weeks'),
            ]);
        });
    }
}
