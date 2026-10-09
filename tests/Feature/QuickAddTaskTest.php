<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class QuickAddTaskTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->editor = User::factory()->create();
        $this->project->setRole($this->editor, ProjectRole::Editor);
    }

    private function list(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->editor)->test('pages::projects.show', ['project' => $this->project]);
    }

    private function board(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->editor)->test('pages::projects.board', ['project' => $this->project]);
    }

    public function test_a_title_in_the_list_creates_a_task_at_the_end_with_the_default_status(): void
    {
        $existing = Task::factory()->for($this->project)->create(['position' => 3]);

        $this->list()->call('quickAdd', '  Angebot schicken  ')->assertSee('Angebot schicken');

        $task = Task::query()->where('title', 'Angebot schicken')->sole();
        $this->assertSame($this->project->defaultStatus()->id, $task->status_id);
        $this->assertSame($this->editor->id, $task->creator_id);
        $this->assertGreaterThan($existing->position, $task->position);
        $this->assertNull($task->parent_id);
        $this->assertTrue($task->activities()->where('type', ActivityType::Created)->exists());
    }

    public function test_an_empty_title_creates_nothing_and_a_too_long_one_says_so(): void
    {
        $this->list()->call('quickAdd', '   ')->assertNotDispatched('toast-show');
        $this->list()->call('quickAdd', str_repeat('x', 256))->assertDispatched('toast-show');

        $this->assertSame(0, Task::query()->count());
    }

    public function test_only_people_who_may_edit_add_tasks_and_only_editors_see_the_field(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        $this->list($viewer)->call('quickAdd', 'Nein')->assertForbidden();
        $this->board($viewer)->call('quickAdd', 'Nein', $this->project->defaultStatus()->id)->assertForbidden();
        $this->assertSame(0, Task::query()->count());

        $this->actingAs($this->editor)->get(route('projects.show', $this->project))->assertOk()->assertSee('Aufgabentitel, dann Enter');
        $this->actingAs($viewer)->get(route('projects.show', $this->project))->assertOk()->assertDontSee('Aufgabentitel, dann Enter');
    }

    public function test_the_filters_of_the_list_decide_what_a_new_task_gets(): void
    {
        $done = $this->project->doneStatus();
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        $other = User::factory()->create();
        $this->project->setRole($other, ProjectRole::Editor);
        $outsider = User::factory()->create();

        $this->list()->set('statusFilter', (string) $done->id)->call('quickAdd', 'Schon erledigt');
        $this->assertSame($done->id, Task::query()->where('title', 'Schon erledigt')->sole()->status_id);

        $this->list()->set('assigneeFilter', 'me')->call('quickAdd', 'Meine');
        $this->assertSame($this->editor->id, Task::query()->where('title', 'Meine')->sole()->assignee_id);

        $this->list()->set('assigneeFilter', (string) $other->id)->call('quickAdd', 'Für die andere Person');
        $this->assertSame($other->id, Task::query()->where('title', 'Für die andere Person')->sole()->assignee_id);

        $this->list()->set('assigneeFilter', (string) $outsider->id)->call('quickAdd', 'Ohne Zugriff');
        $this->assertNull(Task::query()->where('title', 'Ohne Zugriff')->sole()->assignee_id);

        $this->list()->set('tagFilter', (string) $tag->id)->call('quickAdd', 'Mit Tag');
        $this->assertTrue(Task::query()->where('title', 'Mit Tag')->sole()->tags->contains($tag));
    }

    public function test_a_task_the_filters_hide_is_announced(): void
    {
        $this->list()->set('dateFilter', 'dated')->call('quickAdd', 'Ohne Datum')->assertDispatched('toast-show');
        $this->list()->call('quickAdd', 'Sichtbar')->assertNotDispatched('toast-show');
    }

    public function test_a_list_shown_completely_makes_room_for_the_new_task(): void
    {
        // The list shows 50 tasks at first: with exactly 50, the 51st must not end up behind "Load more"
        Task::factory()->for($this->project)->count(50)->create();

        $this->list()->call('quickAdd', 'Die letzte')->assertSee('Die letzte')->assertSet('limit', 51);
    }

    public function test_a_title_in_a_board_column_creates_the_task_at_the_end_of_that_column(): void
    {
        $inProgress = $this->project->statuses[1];
        $first = Task::factory()->for($this->project)->inProgress()->create(['position' => 5]);

        $this->board()->call('quickAdd', 'Neu in Arbeit', $inProgress->id)->assertSee('Neu in Arbeit');

        $task = Task::query()->where('title', 'Neu in Arbeit')->sole();
        $this->assertSame($inProgress->id, $task->status_id);
        $this->assertGreaterThan($first->position, $task->position);
    }

    public function test_a_column_shown_completely_makes_room_and_a_foreign_status_is_refused(): void
    {
        // A column shows 30 cards at first: with exactly 30, the 31st must not end up behind "Load more"
        $status = $this->project->defaultStatus();
        Task::factory()->for($this->project)->count(30)->create();

        $this->board()->call('quickAdd', 'Die letzte', $status->id)->assertSee('Die letzte');

        $foreign = Project::factory()->create()->defaultStatus();
        $this->board()->call('quickAdd', 'Fremd', $foreign->id)->assertNotFound();
        $this->assertSame(0, Task::query()->where('title', 'Fremd')->count());
    }

    public function test_every_board_column_offers_the_field_instead_of_the_dialog(): void
    {
        $this->actingAs($this->editor)->get(route('projects.board', $this->project))->assertOk()
            ->assertSee('Aufgabe hinzufügen')
            ->assertSee('Aufgabentitel, dann Enter')
            ->assertDontSee("new-task', { statusId", false);
    }
}
