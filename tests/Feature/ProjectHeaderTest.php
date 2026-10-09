<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dialogs_of_the_setup_menu_stand_after_the_toolbar_and_only_for_managers(): void
    {
        $project = Project::factory()->create();
        $manager = User::factory()->create();
        $editor = User::factory()->create();
        $project->setRole($manager, ProjectRole::Admin);
        $project->setRole($editor, ProjectRole::Editor);

        foreach (['projects.show', 'projects.board', 'projects.calendar', 'projects.timeline'] as $route) {
            $page = $this->actingAs($manager)->get(route($route, $project))->assertOk()
                ->assertSeeLivewire('project-settings')
                ->assertSeeLivewire('project-statuses')
                ->assertSeeLivewire('project-tags');

            // As flex items of the toolbar their empty roots would each add a gap and push the buttons off the right edge
            $html = $page->getContent();
            $toolbarEnd = strpos($html, 'aria-label="Projekt einrichten"');
            $this->assertNotFalse($toolbarEnd);
            $this->assertGreaterThan($toolbarEnd, strpos($html, 'project-settings'));

            $this->actingAs($editor)->get(route($route, $project))->assertOk()
                ->assertDontSeeLivewire('project-settings')
                ->assertDontSeeLivewire('project-statuses')
                ->assertDontSeeLivewire('project-tags');
        }
    }
}
