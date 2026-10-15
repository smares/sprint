<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-12 09:00');
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create(['name' => 'Website']);
    }

    public function test_board_cards_show_people_due_date_and_how_much_is_attached(): void
    {
        $assignee = User::factory()->create(['name' => 'Anna Beispiel']);
        $task = Task::factory()->for($this->project)->create(['title' => 'Startseite', 'assignee_id' => $assignee->id, 'due_date' => '2026-10-20']);
        $task->collaborators()->attach(User::factory()->count(4)->create());
        Comment::factory()->count(2)->for($task)->create();
        Attachment::factory()->for($task)->create();

        $board = Livewire::test('pages::projects.board', ['project' => $this->project]);

        $board->assertSeeHtml('board-card')
            ->assertSeeHtml('title="Kommentare"')
            ->assertSeeHtml('title="Anhänge"')
            ->assertSee('+2')
            ->assertSeeHtml('data-due="zinc"')
            ->assertSeeHtml('--badge: '.$this->project->defaultStatus()->color);
    }

    public function test_the_due_chip_turns_amber_today_and_tomorrow_and_red_once_passed(): void
    {
        foreach (['2026-10-11' => 'red', '2026-10-12' => 'amber', '2026-10-13' => 'amber', '2026-10-14' => 'zinc'] as $due => $color) {
            Task::factory()->for($this->project)->create(['due_date' => $due]);
        }
        Task::factory()->for($this->project)->done()->create(['due_date' => '2026-10-12']);

        $html = Livewire::test('pages::projects.show', ['project' => $this->project])->set('statusFilter', 'all')->html();

        $this->assertSame(1, substr_count($html, 'data-due="red"'));
        $this->assertSame(2, substr_count($html, 'data-due="amber"'));
        // Later, and done tasks however close their date is
        $this->assertSame(2, substr_count($html, 'data-due="zinc"'));
    }

    public function test_project_cards_show_progress_members_and_the_next_due_date(): void
    {
        $member = User::factory()->create(['name' => 'Bea Mitglied']);
        $this->project->setRole($member, ProjectRole::Editor);
        Task::factory()->for($this->project)->done()->create();
        Task::factory()->for($this->project)->create(['due_date' => '2026-10-15']);
        Task::factory()->for($this->project)->create(['due_date' => '2026-10-30']);
        Task::factory()->for($this->project)->create(['due_date' => '2026-10-01']);

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('1 von 4 erledigt')
            ->assertSee('25 %')
            ->assertSee('aria-valuenow="25"', false)
            ->assertSee('Bea Mitglied')
            ->assertSee('15.10.2026')
            ->assertSee('data-project-icon', false);
    }

    public function test_status_badges_lead_with_a_dot_in_the_status_color(): void
    {
        Task::factory()->for($this->project)->create();

        $this->get(route('projects.show', $this->project))
            ->assertOk()
            ->assertSee('size-1.5 shrink-0 rounded-full bg-(--badge)', false);
    }

    public function test_empty_pages_show_a_friendly_empty_state(): void
    {
        $this->get(route('tasks.mine'))->assertOk()->assertSee('data-empty-state', false)->assertSee('🎉');
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('data-empty-state', false);
    }
}
