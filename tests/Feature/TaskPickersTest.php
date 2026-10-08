<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskPickersTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        $this->task = Task::factory()->for($this->project)->create(['title' => 'Diese Aufgabe']);
    }

    private function page()
    {
        return Livewire::test('pages::tasks.show', ['task' => $this->task]);
    }

    /**
     * @return list<string>
     */
    private function titles($options): array
    {
        return $options->pluck('title')->all();
    }

    public function test_the_pickers_offer_only_the_first_matches_not_every_task_of_the_project(): void
    {
        foreach (range(1, 70) as $number) {
            Task::factory()->for($this->project)->create(['title' => sprintf('Aufgabe %02d', $number)]);
        }

        $page = $this->page();

        $this->assertCount(50, $page->instance()->blockerOptions);
        $this->assertCount(50, $page->instance()->blockingOptions);
        $this->assertCount(50, $page->instance()->parentOptions);
        $this->assertNotContains('Diese Aufgabe', $this->titles($page->instance()->blockerOptions));
    }

    public function test_the_search_finds_tasks_beyond_the_first_matches(): void
    {
        foreach (range(1, 70) as $number) {
            Task::factory()->for($this->project)->create(['title' => sprintf('Aufgabe %02d', $number)]);
        }

        $page = $this->page()->set('blockerSearch', 'Aufgabe 68');

        $this->assertSame(['Aufgabe 68'], $this->titles($page->instance()->blockerOptions));
    }

    public function test_what_is_already_chosen_stays_in_the_list_whatever_was_searched(): void
    {
        $blocker = Task::factory()->for($this->project)->create(['title' => 'Zuletzt im Alphabet']);
        foreach (range(1, 60) as $number) {
            Task::factory()->for($this->project)->create(['title' => sprintf('Aufgabe %02d', $number)]);
        }
        $this->task->blockers()->attach($blocker);

        $page = $this->page();

        $this->assertContains('Zuletzt im Alphabet', $this->titles($page->instance()->blockerOptions));

        $page->set('blockerSearch', 'Aufgabe 05');

        $this->assertEqualsCanonicalizing(['Zuletzt im Alphabet', 'Aufgabe 05'], $this->titles($page->instance()->blockerOptions));
    }

    public function test_the_parent_picker_leaves_out_the_task_itself_its_subtasks_and_headings(): void
    {
        $child = Task::factory()->for($this->project)->create(['title' => 'Kind', 'parent_id' => $this->task->id]);
        Task::factory()->for($this->project)->create(['title' => 'Enkel', 'parent_id' => $child->id]);
        Task::factory()->for($this->project)->create(['title' => 'Überschrift', 'is_section' => true]);
        Task::factory()->for($this->project)->create(['title' => 'Daneben']);

        $this->assertSame(['Daneben'], $this->titles($this->page()->instance()->parentOptions));
    }

    public function test_tasks_of_other_projects_are_never_offered(): void
    {
        Task::factory()->for(Project::factory()->create())->create(['title' => 'Fremd']);

        $page = $this->page()->set('parentSearch', 'Fremd')->set('blockerSearch', 'Fremd');

        $this->assertSame([], $this->titles($page->instance()->parentOptions));
        $this->assertSame([], $this->titles($page->instance()->blockerOptions));
    }

    public function test_a_parent_is_only_accepted_from_the_same_project_and_not_from_below_itself(): void
    {
        $child = Task::factory()->for($this->project)->create(['parent_id' => $this->task->id]);
        $heading = Task::factory()->for($this->project)->create(['is_section' => true]);
        $foreign = Task::factory()->for(Project::factory()->create())->create();
        $valid = Task::factory()->for($this->project)->create();

        foreach ([$this->task->id, $child->id, $heading->id, $foreign->id] as $invalid) {
            $this->page()->set('parentId', (string) $invalid)->call('save')->assertHasErrors('parentId');
        }

        $this->page()->set('parentId', (string) $valid->id)->call('save')->assertHasNoErrors();
        $this->assertSame($valid->id, $this->task->fresh()->parent_id);
    }

    public function test_dependencies_can_be_saved_for_tasks_the_search_did_not_show(): void
    {
        foreach (range(1, 60) as $number) {
            Task::factory()->for($this->project)->create(['title' => sprintf('Aufgabe %02d', $number)]);
        }
        $hidden = Task::where('title', 'Aufgabe 60')->firstOrFail();

        $this->page()->set('blockerIds', [(string) $hidden->id])->call('save')->assertHasNoErrors();

        $this->assertSame([$hidden->id], $this->task->blockers()->pluck('tasks.id')->all());
    }
}
