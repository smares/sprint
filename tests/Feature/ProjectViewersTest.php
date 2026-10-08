<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectViewersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_admins_members_and_team_members_but_no_outsiders_in_a_few_queries(): void
    {
        $project = Project::factory()->create();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $teamMember = User::factory()->create();
        $outsider = User::factory()->create();
        $project->setRole($member, ProjectRole::Viewer);
        $team = Team::factory()->create();
        $team->users()->attach($teamMember);
        $project->setTeamRole($team, ProjectRole::Editor);

        $people = collect([$admin, $member, $teamMember, $outsider]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $viewers = $project->viewerIds($people);

        $this->assertEqualsCanonicalizing([$admin->id, $member->id, $teamMember->id], $viewers);
        $this->assertSame(2, $queries);
    }

    public function test_it_agrees_with_the_role_check_for_every_person(): void
    {
        $project = Project::factory()->create();
        $people = User::factory()->count(3)->create();
        $project->setRole($people[0], ProjectRole::Admin);

        $viewers = $project->viewerIds($people);

        foreach ($people as $person) {
            $this->assertSame($project->canBeViewedBy($person), in_array($person->id, $viewers, true));
        }
    }
}
