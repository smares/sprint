<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Enums\RepeatMode;
use App\Enums\RepeatUnit;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicateTaskTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->user, ProjectRole::Editor);
        $this->actingAs($this->user);
    }

    private function original(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes + [
            'title' => 'Angebot', 'description' => 'Bitte beachten', 'assignee_id' => $this->user->id,
            'due_date' => '2026-10-20', 'start_date' => '2026-10-15', 'position' => 3,
        ]);
    }

    public function test_a_copy_keeps_the_content_and_starts_open(): void
    {
        $helper = User::factory()->create();
        $tag = Tag::factory()->for($this->project)->create();
        $priority = $this->project->customFields()->firstOrFail();
        $original = $this->original(['status_id' => $this->project->doneStatus()->id]);
        $original->collaborators()->attach($helper);
        $original->tags()->attach($tag);
        $original->fieldValues()->create(['custom_field_id' => $priority->id, 'option_id' => $priority->options->first()->id]);

        $copy = $original->duplicate();

        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame('Angebot (Kopie)', $copy->title);
        $this->assertSame('Bitte beachten', $copy->description);
        $this->assertSame($this->user->id, $copy->assignee_id);
        $this->assertSame('2026-10-20', $copy->due_date->toDateString());
        $this->assertSame('2026-10-15', $copy->start_date->toDateString());
        $this->assertSame($this->project->defaultStatus()->id, $copy->status_id);
        $this->assertFalse($copy->isDone());
        $this->assertSame([$helper->id], $copy->collaborators()->pluck('users.id')->all());
        $this->assertSame([$tag->id], $copy->tags()->pluck('tags.id')->all());
        $this->assertSame($priority->options->first()->id, $copy->fieldValues()->firstOrFail()->option_id);
    }

    public function test_comments_attachments_dependencies_and_the_recurrence_rule_are_not_copied(): void
    {
        $original = $this->original(['repeat_unit' => RepeatUnit::Week, 'repeat_interval' => 1, 'repeat_mode' => RepeatMode::Schedule]);
        Comment::factory()->create(['task_id' => $original->id, 'user_id' => $this->user->id]);
        Attachment::factory()->create(['task_id' => $original->id]);
        $original->blockers()->attach(Task::factory()->for($this->project)->create());

        $copy = $original->duplicate();

        $this->assertSame(0, $copy->comments()->count());
        $this->assertSame(0, $copy->attachments()->count());
        $this->assertSame(0, $copy->blockers()->count());
        $this->assertNull($copy->repeat_unit);
        $this->assertSame(RepeatUnit::Week, $original->fresh()->repeat_unit);
    }

    public function test_subtasks_and_headings_are_copied_recursively_and_open(): void
    {
        $original = $this->original();
        Task::factory()->for($this->project)->create(['parent_id' => $original->id, 'is_section' => true, 'title' => 'Vorbereitung', 'position' => 0]);
        $child = Task::factory()->for($this->project)->done()->create(['parent_id' => $original->id, 'title' => 'Zahlen', 'position' => 1]);
        Task::factory()->for($this->project)->create(['parent_id' => $child->id, 'title' => 'Tief', 'position' => 0]);

        $copy = $original->duplicate();

        $children = $copy->children()->get();
        $this->assertSame(['Vorbereitung', 'Zahlen'], $children->pluck('title')->all());
        $this->assertTrue($children[0]->is_section);
        $this->assertFalse($children[1]->isDone());
        $this->assertSame(['Tief'], $children[1]->children()->pluck('title')->all());
        $this->assertSame(['Vorbereitung', 'Zahlen'], $original->children()->pluck('title')->all());
    }

    public function test_the_copy_lands_right_behind_the_original(): void
    {
        $first = $this->original(['title' => 'Eins', 'position' => 0]);
        $original = $this->original(['title' => 'Zwei', 'position' => 1]);
        $last = $this->original(['title' => 'Drei', 'position' => 2]);

        $original->duplicate();

        $this->assertSame(['Eins', 'Zwei', 'Zwei (Kopie)', 'Drei'], $this->project->tasks()->whereNull('parent_id')->orderBy('position')->orderBy('id')->pluck('title')->all());
        $this->assertNotNull($first);
        $this->assertNotNull($last);
    }

    public function test_a_subtask_is_copied_next_to_itself(): void
    {
        $parent = $this->original();
        $child = Task::factory()->for($this->project)->create(['parent_id' => $parent->id, 'title' => 'Unter']);

        $copy = $child->duplicate();

        $this->assertSame($parent->id, $copy->parent_id);
        $this->assertSame(['Unter', 'Unter (Kopie)'], $parent->children()->pluck('title')->all());
    }

    public function test_very_long_titles_still_fit(): void
    {
        $copy = $this->original(['title' => str_repeat('x', 255)])->duplicate();

        $this->assertSame(255, mb_strlen($copy->title));
        $this->assertStringEndsWith(' (Kopie)', $copy->title);
    }

    public function test_the_original_logs_that_it_was_duplicated(): void
    {
        $original = $this->original();

        $original->duplicate();

        $this->assertContains('hat die Aufgabe dupliziert', $original->activities()->get()->map->sentence()->all());
    }

    public function test_the_page_duplicates_and_opens_the_copy(): void
    {
        $original = $this->original();

        $page = Livewire::test('pages::tasks.show', ['task' => $original])->call('duplicate');

        $copy = Task::where('title', 'Angebot (Kopie)')->firstOrFail();
        $page->assertRedirect(route('tasks.show', $copy));
    }

    public function test_the_panel_announces_the_copy_instead_of_redirecting(): void
    {
        $original = $this->original();

        Livewire::test('pages::tasks.show', ['task' => $original, 'panel' => true])
            ->call('duplicate')->assertNoRedirect()->assertDispatched('task-changed')->assertDispatched('open-task');
    }

    public function test_viewers_and_archived_projects_cannot_duplicate(): void
    {
        $original = $this->original();
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        Livewire::actingAs($viewer)->test('pages::tasks.show', ['task' => $original])->call('duplicate')->assertForbidden();

        $this->project->update(['archived_at' => now()]);
        Livewire::test('pages::tasks.show', ['task' => $original->fresh()])->call('duplicate')->assertForbidden();

        $this->assertSame(1, Task::count());
    }

    public function test_headings_cannot_be_duplicated_through_the_page(): void
    {
        $heading = Task::factory()->for($this->project)->create(['is_section' => true, 'title' => 'Abschnitt']);

        Livewire::test('pages::tasks.show', ['task' => $heading])->call('duplicate')->assertNotFound();
    }

    public function test_the_button_is_only_shown_to_editors(): void
    {
        $original = $this->original();

        $this->get(route('tasks.show', $original))->assertSee('Duplizieren');

        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer)->get(route('tasks.show', $original))->assertDontSee('Duplizieren');
    }

    public function test_recurring_tasks_still_copy_what_they_did_before(): void
    {
        $this->travelTo('2026-10-07 10:00:00');
        $original = $this->original(['due_date' => '2026-10-09', 'start_date' => null, 'repeat_unit' => RepeatUnit::Week, 'repeat_interval' => 1, 'repeat_mode' => RepeatMode::Schedule]);
        Task::factory()->for($this->project)->create(['parent_id' => $original->id, 'title' => 'Teil', 'due_date' => '2026-10-08']);

        $original->toggleDone();

        $next = Task::where('title', 'Angebot')->where('id', '!=', $original->id)->firstOrFail();
        $this->assertSame('2026-10-16', $next->due_date->toDateString());
        $this->assertSame(RepeatUnit::Week, $next->repeat_unit);
        $this->assertSame('2026-10-15', $next->children()->firstOrFail()->due_date->toDateString());
    }
}
