<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\WorkloadService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkloadTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $anna;

    private Project $project;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday: this week runs to Sunday 2026-10-11, the fourth week ends on 2026-11-01
        $this->travelTo('2026-10-08 10:00:00');
        $this->me = User::factory()->create(['name' => 'Olaf']);
        $this->anna = User::factory()->create(['name' => 'Anna']);
        $this->project = Project::factory()->create();
        $this->project->setRole($this->me, ProjectRole::Editor);
        $this->project->setRole($this->anna, ProjectRole::Editor);
        $this->team = Team::factory()->create(['name' => 'Web']);
        $this->team->users()->attach([$this->me->id, $this->anna->id]);
        $this->actingAs($this->me);
    }

    private function due(User $assignee, ?string $date, array $attributes = []): Task
    {
        return Task::factory()->for($attributes['project'] ?? $this->project)->create(['assignee_id' => $assignee->id, 'due_date' => $date] + array_diff_key($attributes, ['project' => 1]));
    }

    public function test_open_tasks_land_in_overdue_the_weeks_ahead_or_without_due_date(): void
    {
        $this->due($this->anna, '2026-10-01', ['title' => 'Verpasst']);
        $this->due($this->anna, '2026-10-08', ['title' => 'Heute']);
        $this->due($this->anna, '2026-10-11', ['title' => 'Sonntag']);
        $this->due($this->anna, '2026-10-12', ['title' => 'Nächste Woche']);
        $this->due($this->anna, '2026-11-01', ['title' => 'Letzter Tag']);
        $this->due($this->anna, '2026-11-02', ['title' => 'Zu weit']);
        $this->due($this->anna, null, ['title' => 'Irgendwann']);
        $this->due($this->anna, '2026-10-09', ['title' => 'Erledigt'])->update(['status_id' => $this->project->doneStatus()->id]);
        $this->due($this->anna, '2026-10-09', ['title' => 'Abschnitt', 'is_section' => true]);

        $load = app(WorkloadService::class)->forPeople(new Collection([$this->anna]), $this->me)[$this->anna->id];

        $this->assertSame(['Verpasst'], $load['overdue']);
        $this->assertSame([['Heute', 'Sonntag'], ['Nächste Woche'], [], ['Letzter Tag']], array_column($load['weeks'], 'titles'));
        $this->assertSame(1, $load['undated']);
    }

    public function test_only_tasks_from_projects_the_viewer_sees_and_not_archived_ones_count(): void
    {
        $hidden = Project::factory()->create();
        $hidden->setRole($this->anna, ProjectRole::Editor);
        $archived = Project::factory()->create(['archived_at' => now()]);
        $archived->setRole($this->me, ProjectRole::Editor);
        $archived->setRole($this->anna, ProjectRole::Editor);
        $this->due($this->anna, '2026-10-09', ['title' => 'Sichtbar']);
        $this->due($this->anna, '2026-10-09', ['title' => 'Geheim', 'project' => $hidden]);
        $this->due($this->anna, '2026-10-09', ['title' => 'Archiviert', 'project' => $archived]);

        $load = app(WorkloadService::class)->forPeople(new Collection([$this->anna]), $this->me)[$this->anna->id];

        $this->assertSame(['Sichtbar'], $load['weeks'][0]['titles']);
    }

    public function test_absences_count_the_weekdays_away_in_each_week(): void
    {
        // Away from Friday this week to Tuesday the week after (the weekend in between does not count)
        $this->anna->update(['absent_from' => '2026-10-09', 'absent_until' => '2026-10-13']);

        $load = app(WorkloadService::class)->forPeople(new Collection([$this->anna]), $this->me)[$this->anna->id];

        $this->assertSame([1, 2, 0, 0], array_column($load['weeks'], 'absent_days'));
    }

    public function test_the_page_shows_oneself_a_team_of_ones_own_and_everyone_only_to_admins(): void
    {
        $stranger = User::factory()->create(['name' => 'Zora']);
        $otherTeam = Team::factory()->create(['name' => 'Fremd']);
        $otherTeam->users()->attach($stranger);
        $this->due($this->anna, '2026-10-09', ['title' => 'Für Anna']);
        $this->anna->update(['absent_from' => '2026-10-09', 'absent_until' => '2026-10-09']);

        $this->get(route('workload'))->assertOk()->assertSee('Auslastung')->assertSee('Team Web')->assertDontSee('Team Fremd')->assertDontSee('>Alle<', false);

        $page = Livewire::test('pages::workload')->assertSee('Olaf')->assertDontSee('Anna');

        $page->set('scope', 'team:'.$this->team->id)
            ->assertSeeInOrder(['Anna', 'Olaf'])
            ->assertSee('1 Tag weg')
            ->assertSeeHtml('title="Für Anna"');

        // Other teams and "everyone" are for admins only; an invalid choice shows oneself
        $page->set('scope', 'team:'.$otherTeam->id)->assertDontSee('Zora')->assertSee('Olaf');
        $page->set('scope', 'all')->assertDontSee('Zora');

        $this->me->forceFill(['is_admin' => true])->save();
        Livewire::test('pages::workload')->set('scope', 'all')->assertSee('Zora')->assertSee('Anna');
    }

    public function test_the_navigation_and_the_command_palette_lead_to_it(): void
    {
        $this->get(route('projects.index'))->assertSee(route('workload'), false);
        Livewire::test('command-palette')->assertSee('Auslastung');
    }
}
