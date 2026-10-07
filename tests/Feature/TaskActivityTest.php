<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create(['name' => 'Anna Autorin']);
        $this->actingAs($this->user);
    }

    /**
     * @return list<string>
     */
    private function types(Task $task): array
    {
        return $task->activities()->orderBy('id')->pluck('type')->all();
    }

    public function test_creating_a_task_is_recorded_with_the_person(): void
    {
        $task = Task::factory()->create();

        $activity = $task->activities()->firstOrFail();
        $this->assertSame('created', $activity->type);
        $this->assertSame($this->user->id, $activity->user_id);
        $this->assertSame('hat die Aufgabe angelegt', $activity->sentence());
    }

    public function test_section_headings_are_not_recorded(): void
    {
        $section = Task::factory()->create(['is_section' => true]);
        $section->update(['title' => 'Neu']);

        $this->assertSame([], $this->types($section));
    }

    public function test_status_change_names_old_and_new_status(): void
    {
        $task = Task::factory()->create();
        $task->update(['status_id' => $task->project->statuses[1]->id]);

        $activity = $task->activities()->where('type', 'status_changed')->firstOrFail();
        $this->assertSame('hat den Status von „Offen“ auf „In Arbeit“ geändert', $activity->sentence());
    }

    public function test_assignee_due_date_title_and_description_changes_are_recorded(): void
    {
        $task = Task::factory()->create(['title' => 'Alt', 'due_date' => '2026-12-01']);
        $other = User::factory()->admin()->create(['name' => 'Ben Muster']);

        $task->update(['assignee_id' => $other->id, 'due_date' => '2026-12-24', 'title' => 'Neu', 'description' => 'Text']);

        $sentences = $task->activities()->orderBy('id')->get()->map->sentence()->all();

        $this->assertContains('hat die Zuständigkeit von – auf Ben Muster geändert', $sentences);
        $this->assertContains('hat die Fälligkeit von 01.12.2026 auf 24.12.2026 geändert', $sentences);
        $this->assertContains('hat den Titel von „Alt“ in „Neu“ geändert', $sentences);
        $this->assertContains('hat die Beschreibung geändert', $sentences);
    }

    public function test_unchanged_saves_record_nothing(): void
    {
        $task = Task::factory()->create();
        $before = $task->activities()->count();

        $task->update(['title' => $task->title]);

        $this->assertSame($before, $task->activities()->count());
    }

    public function test_task_page_records_tag_collaborator_and_dependency_changes(): void
    {
        $task = Task::factory()->create();
        $tag = Tag::factory()->for($task->project)->create(['name' => 'Bug']);
        $helper = User::factory()->admin()->create(['name' => 'Clara Test']);
        $blocker = Task::factory()->for($task->project)->create(['title' => 'Vorarbeit']);
        $waiting = Task::factory()->for($task->project)->create(['title' => 'Folgearbeit']);

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('tagIds', [(string) $tag->id])
            ->set('collaboratorIds', [(string) $helper->id])
            ->set('blockerIds', [(string) $blocker->id])
            ->set('blockingIds', [(string) $waiting->id])
            ->call('save')
            ->assertHasNoErrors();

        $sentences = $task->activities()->orderBy('id')->get()->map->sentence()->all();

        $this->assertContains('hat die Tags Bug hinzugefügt', $sentences);
        $this->assertContains('hat Clara Test als Beteiligte hinzugefügt', $sentences);
        $this->assertContains('hat Vorarbeit als Blocker hinzugefügt', $sentences);
        $this->assertContains('blockiert jetzt Folgearbeit', $sentences);

        Livewire::test('pages::tasks.show', ['task' => $task->fresh()])
            ->set('tagIds', [])
            ->set('collaboratorIds', [])
            ->set('blockerIds', [])
            ->set('blockingIds', [])
            ->call('save');

        $sentences = $task->activities()->orderBy('id')->get()->map->sentence()->all();

        $this->assertContains('hat die Tags Bug entfernt', $sentences);
        $this->assertContains('hat Clara Test als Beteiligte entfernt', $sentences);
        $this->assertContains('hat Vorarbeit als Blocker entfernt', $sentences);
        $this->assertContains('blockiert Folgearbeit nicht mehr', $sentences);
    }

    public function test_saving_without_changes_adds_no_activity(): void
    {
        $task = Task::factory()->create();
        $before = $task->activities()->count();

        Livewire::test('pages::tasks.show', ['task' => $task])->call('save')->assertHasNoErrors();

        $this->assertSame($before, $task->activities()->count());
    }

    public function test_rejected_cyclic_dependencies_leave_no_trace(): void
    {
        $task = Task::factory()->create();
        $other = Task::factory()->for($task->project)->create();
        $other->blockers()->attach($task);
        $before = $task->activities()->count();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('blockerIds', [(string) $other->id])
            ->call('save')
            ->assertHasErrors('blockingIds');

        $this->assertSame($before, $task->activities()->count());
    }

    public function test_moving_under_another_task_is_recorded(): void
    {
        $task = Task::factory()->create();
        $parent = Task::factory()->for($task->project)->create(['title' => 'Oben']);

        Livewire::test('pages::tasks.show', ['task' => $task])->set('parentId', (string) $parent->id)->call('save');

        $this->assertContains('hat die Aufgabe unter „Oben“ verschoben', $task->activities()->get()->map->sentence()->all());
    }

    public function test_page_shows_comments_and_changes_in_time_order(): void
    {
        $task = Task::factory()->create();
        $this->travelTo(now()->addMinute());
        Comment::factory()->for($task)->create(['user_id' => $this->user->id, 'body' => 'Mein Kommentar']);
        $this->travelTo(now()->addMinute());
        $task->update(['title' => 'Anderer Titel']);

        $this->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSeeInOrder(['hat die Aufgabe angelegt', 'Mein Kommentar', 'hat den Titel von'])
            ->assertSee('Anna Autorin');
    }

    public function test_deleted_people_show_as_someone(): void
    {
        $task = Task::factory()->create();
        $this->user->delete();
        auth()->logout();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('tasks.show', $task))->assertOk()->assertSee('Jemand');
    }

    public function test_activities_are_deleted_with_the_task(): void
    {
        $task = Task::factory()->create();
        TaskActivity::factory()->for($task)->create();

        $task->delete();

        $this->assertDatabaseCount('task_activities', 0);
    }
}
