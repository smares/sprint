<?php

namespace Database\Seeders;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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
                'is_admin' => $name === 'Anna Beispiel',
            ])
        );

        Project::factory(2)->create()->each(function (Project $project) use ($users) {
            $users->each(fn (User $user) => $project->setRole($user, $user->is_admin ? ProjectRole::Admin : ProjectRole::Editor));

            Task::factory(5)->for($project)->create([
                'creator_id' => $users->first()->id,
                'assignee_id' => fn () => $users->random()->id,
                'status_id' => fn () => $project->statuses->random()->id,
                'due_date' => fn () => fake()->optional(0.7)->dateTimeBetween('-3 days', '+3 weeks'),
            ]);
        });
    }
}
