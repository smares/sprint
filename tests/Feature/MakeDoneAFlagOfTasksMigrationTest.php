<?php

namespace Tests\Feature;

use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MakeDoneAFlagOfTasksMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private int $doneStatusId;

    private int $otherDoneStatusId;

    private int $openStatusId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 10:00:00');
        $this->project = Project::factory()->create();
        $this->openStatusId = $this->project->defaultStatus()->id;

        // The shape before the migration: statuses carry the mark
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->boolean('is_done')->default(false);
        });

        $this->doneStatusId = $this->statusMarkedDone('Erledigt', 2);
        $this->otherDoneStatusId = $this->statusMarkedDone('Abgeschlossen', 3);
    }

    private function statusMarkedDone(string $name, int $position): int
    {
        return DB::table('task_statuses')->insertGetId([
            'project_id' => $this->project->id, 'name' => $name, 'color' => '#22c55e', 'position' => $position, 'is_done' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_06_200000_make_done_a_flag_of_tasks.php');
    }

    /**
     * @param  list<array{type: string, value: mixed}>  $actions
     */
    private function rule(string $name, AutomationTrigger $trigger, ?int $value, array $actions): Automation
    {
        return Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => User::factory()->create()->id,
            'name' => $name,
            'trigger' => $trigger,
            'trigger_value' => $value,
            'actions' => $actions,
        ]);
    }

    public function test_tasks_in_a_done_status_are_finished_and_stay_where_they_are(): void
    {
        $inDone = Task::factory()->for($this->project)->create(['status_id' => $this->doneStatusId]);
        $inOtherDone = Task::factory()->for($this->project)->create(['status_id' => $this->otherDoneStatusId]);
        $alreadyTimed = Task::factory()->for($this->project)->create(['status_id' => $this->doneStatusId, 'completed_at' => '2026-09-01 12:00:00']);
        $open = Task::factory()->for($this->project)->create(['status_id' => $this->openStatusId]);
        $this->assertNull($inDone->fresh()->completed_at);

        $this->migration()->up();

        $this->assertTrue($inDone->fresh()->isDone());
        $this->assertTrue($inOtherDone->fresh()->isDone());
        $this->assertSame('2026-09-01 12:00:00', $alreadyTimed->fresh()->completed_at->toDateTimeString());
        $this->assertSame($this->doneStatusId, $inDone->fresh()->status_id);
        $this->assertSame($this->otherDoneStatusId, $inOtherDone->fresh()->status_id);
        $this->assertFalse($open->fresh()->isDone());
        $this->assertSame($this->openStatusId, $open->fresh()->status_id);
    }

    public function test_the_column_is_dropped_and_the_statuses_stay(): void
    {
        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('task_statuses', 'is_done'));
        $this->assertSame(4, $this->project->statuses()->count());
    }

    public function test_rules_that_react_to_a_done_status_react_to_completion(): void
    {
        $toDone = $this->rule('Fertig', AutomationTrigger::StatusChanged, $this->doneStatusId, [['type' => 'comment', 'value' => 'Danke']]);
        $toOtherDone = $this->rule('Abgeschlossen', AutomationTrigger::StatusChanged, $this->otherDoneStatusId, [['type' => 'comment', 'value' => 'Danke']]);
        $toOpen = $this->rule('Offen', AutomationTrigger::StatusChanged, $this->openStatusId, [['type' => 'comment', 'value' => 'Wieder offen']]);
        $tag = $this->rule('Assignee', AutomationTrigger::AssigneeChanged, null, [['type' => 'comment', 'value' => 'x']]);

        $this->migration()->up();

        $this->assertSame(AutomationTrigger::Completed, $toDone->fresh()->trigger);
        $this->assertNull($toDone->fresh()->trigger_value);
        $this->assertSame(AutomationTrigger::Completed, $toOtherDone->fresh()->trigger);
        $this->assertNull($toOtherDone->fresh()->trigger_value);
        $this->assertSame(AutomationTrigger::StatusChanged, $toOpen->fresh()->trigger);
        $this->assertSame($this->openStatusId, $toOpen->fresh()->trigger_value);
        $this->assertSame(AutomationTrigger::AssigneeChanged, $tag->fresh()->trigger);
    }

    public function test_actions_that_set_a_done_status_complete_the_task_and_the_others_are_kept(): void
    {
        $rule = $this->rule('Abschluss', AutomationTrigger::AssigneeChanged, null, [
            ['type' => 'comment', 'value' => 'Los'],
            ['type' => 'set_status', 'value' => $this->doneStatusId],
            ['type' => 'set_status', 'value' => $this->openStatusId],
            ['type' => 'set_status', 'value' => $this->otherDoneStatusId],
        ]);
        $untouched = $this->rule('Unberührt', AutomationTrigger::AssigneeChanged, null, [['type' => 'set_status', 'value' => $this->openStatusId]]);

        $this->migration()->up();

        $this->assertEquals([
            ['type' => AutomationAction::Comment->value, 'value' => 'Los'],
            ['type' => AutomationAction::Complete->value, 'value' => null],
            ['type' => AutomationAction::SetStatus->value, 'value' => $this->openStatusId],
            ['type' => AutomationAction::Complete->value, 'value' => null],
        ], $rule->fresh()->actions);
        $this->assertEquals([['type' => 'set_status', 'value' => $this->openStatusId]], $untouched->fresh()->actions);
    }

    public function test_without_any_done_status_only_the_column_goes(): void
    {
        DB::table('task_statuses')->update(['is_done' => false]);
        $task = Task::factory()->for($this->project)->create(['status_id' => $this->doneStatusId]);
        $rule = $this->rule('Status', AutomationTrigger::StatusChanged, $this->doneStatusId, [['type' => 'set_status', 'value' => $this->doneStatusId]]);

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('task_statuses', 'is_done'));
        $this->assertFalse($task->fresh()->isDone());
        $this->assertSame(AutomationTrigger::StatusChanged, $rule->fresh()->trigger);
        $this->assertEquals([['type' => 'set_status', 'value' => $this->doneStatusId]], $rule->fresh()->actions);
    }

    public function test_going_back_marks_the_last_status_of_each_project_as_done(): void
    {
        $other = Project::factory()->create();
        $migration = $this->migration();
        $migration->up();

        $migration->down();

        $this->assertTrue(Schema::hasColumn('task_statuses', 'is_done'));
        $this->assertSame([$this->otherDoneStatusId], DB::table('task_statuses')->where('project_id', $this->project->id)->where('is_done', true)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$other->statuses()->get()->last()->id], DB::table('task_statuses')->where('project_id', $other->id)->where('is_done', true)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }
}
