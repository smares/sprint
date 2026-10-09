<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectDueCountsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 0));
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create(['name' => 'Webseite']);
    }

    private function task(string $title, ?string $due, bool $done = false, ?Task $parent = null): Task
    {
        $factory = Task::factory()->for($this->project);

        return ($done ? $factory->done() : $factory)->create(['title' => $title, 'due_date' => $due, 'parent_id' => $parent?->id]);
    }

    public function test_the_card_shows_overdue_and_due_this_week_counts_linking_to_the_filtered_list(): void
    {
        $this->task('Gestern fällig', '2026-10-08');
        $this->task('Längst fällig', '2026-09-01');
        $this->task('Heute fällig', '2026-10-09');
        $this->task('In sechs Tagen', '2026-10-15');
        $this->task('In sieben Tagen', '2026-10-16');
        $this->task('Erledigt und überfällig', '2026-10-01', done: true);
        $this->task('Unteraufgabe überfällig', '2026-10-01', parent: $this->task('Ohne Datum', null));

        $project = Livewire::test('pages::projects.index')->instance()->projects->first();
        $this->assertSame([2, 2], [$project->overdue_tasks_count, $project->due_soon_tasks_count]);

        $this->get(route('projects.index'))->assertOk()
            ->assertSee('2 überfällig')->assertSee('2 diese Woche fällig')
            ->assertSee(route('projects.show', [$this->project, 'dates' => 'overdue']), false)
            ->assertSee(route('projects.show', [$this->project, 'dates' => 'soon']), false)
            ->assertDontSee('gesamt');
    }

    public function test_the_counts_only_show_when_there_is_something(): void
    {
        $this->task('Nächsten Monat', '2026-11-20');

        $this->get(route('projects.index'))->assertOk()
            ->assertSee('1 offen')->assertDontSee('überfällig')->assertDontSee('diese Woche fällig');
    }

    public function test_the_list_filters_overdue_and_due_this_week(): void
    {
        $this->task('Gestern fällig', '2026-10-08');
        $this->task('Heute fällig', '2026-10-09');
        $this->task('Nächsten Monat', '2026-11-20');
        $this->task('Erledigt und überfällig', '2026-10-01', done: true);

        Livewire::withQueryParams(['dates' => 'overdue'])->test('pages::projects.show', ['project' => $this->project])
            ->assertSee('Gestern fällig')->assertDontSee('Heute fällig')->assertDontSee('Nächsten Monat')->assertDontSee('Erledigt und überfällig')
            ->assertSee('Überfällig')
            ->set('dateFilter', 'soon')
            ->assertSee('Heute fällig')->assertDontSee('Gestern fällig')->assertDontSee('Nächsten Monat')
            ->call('clearFilter', 'dates')
            ->assertSee('Gestern fällig')->assertSee('Heute fällig')->assertSee('Nächsten Monat');
    }

    public function test_task_scopes_use_the_days_of_the_app_time_zone(): void
    {
        $this->task('Gestern fällig', '2026-10-08');
        $this->task('Heute fällig', '2026-10-09');

        $this->travelTo(now()->setTime(23, 30));

        $this->assertSame(['Gestern fällig'], Task::query()->overdue()->pluck('title')->all());
        $this->assertSame(['Heute fällig'], Task::query()->dueSoon()->pluck('title')->all());
    }
}
