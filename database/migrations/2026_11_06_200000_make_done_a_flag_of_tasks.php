<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Done is a flag of the task (`completed_at`), not a status any more: the statuses lose their "done" mark. What the mark
     * did stays as it was for what exists: the tasks in a done status are finished (they already have the time), and the
     * rules of a project that reacted to or set a done status finish the task instead. The statuses themselves stay, so
     * the tasks stay where they are.
     */
    public function up(): void
    {
        $doneStatuses = DB::table('task_statuses')->where('is_done', true)->select('id');

        DB::table('tasks')->whereIn('status_id', $doneStatuses)->whereNull('completed_at')->update(['completed_at' => now()]);

        DB::table('automations')->where('trigger', 'status_changed')->whereIn('trigger_value', $doneStatuses)
            ->update(['trigger' => 'completed', 'trigger_value' => null]);

        $doneStatusIds = DB::table('task_statuses')->where('is_done', true)->pluck('id')->map(fn (mixed $id) => (int) $id)->all();

        $this->rewriteActions(fn (array $action) => $this->isSetStatusTo($action, $doneStatusIds) ? ['type' => 'complete', 'value' => null] : $action);

        Schema::table('task_statuses', function (Blueprint $table) {
            $table->dropColumn('is_done');
        });
    }

    /**
     * Back to a done status per project: the one that has the name of one ("Done", "Erledigt"), else a new one at the end. The
     * finished tasks move into it (the column they stood in is not known any more), and the rules that finish a task set or
     * wait for that status again.
     */
    public function down(): void
    {
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->boolean('is_done')->default(false)->after('position');
        });

        $doneStatusByProject = [];

        foreach (DB::table('task_statuses')->select('project_id')->distinct()->pluck('project_id') as $projectId) {
            $statuses = DB::table('task_statuses')->where('project_id', $projectId)->orderBy('position')->orderBy('id')->get();
            $named = $statuses->first(fn (object $status) => in_array(mb_strtolower($status->name), ['done', 'erledigt'], true));

            $doneStatusByProject[$projectId] = (int) ($named->id ?? DB::table('task_statuses')->insertGetId([
                'project_id' => $projectId,
                'name' => 'Done',
                'color' => '#22c55e',
                'position' => ((int) $statuses->max('position')) + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            DB::table('task_statuses')->where('id', $doneStatusByProject[$projectId])->update(['is_done' => true]);
            DB::table('tasks')->where('project_id', $projectId)->whereNotNull('completed_at')->update(['status_id' => $doneStatusByProject[$projectId]]);
        }

        foreach (DB::table('automations')->where('trigger', 'completed')->get(['id', 'project_id']) as $rule) {
            DB::table('automations')->where('id', $rule->id)->update(['trigger' => 'status_changed', 'trigger_value' => $doneStatusByProject[$rule->project_id] ?? null]);
        }

        foreach ($doneStatusByProject as $projectId => $doneStatusId) {
            $this->rewriteActions(fn (array $action) => ($action['type'] ?? null) === 'complete' ? ['type' => 'set_status', 'value' => $doneStatusId] : $action, $projectId);
        }
    }

    /**
     * @param  list<int>  $statusIds
     */
    private function isSetStatusTo(array $action, array $statusIds): bool
    {
        return ($action['type'] ?? null) === 'set_status' && in_array((int) ($action['value'] ?? 0), $statusIds, true);
    }

    /**
     * Rewrite the actions of the rules (of one project) where the callback changes an action.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $rewrite
     */
    private function rewriteActions(callable $rewrite, ?int $projectId = null): void
    {
        $rules = DB::table('automations')->select('id', 'actions')->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))->orderBy('id');

        foreach ($rules->get() as $rule) {
            $actions = json_decode($rule->actions, true) ?: [];
            $rewritten = array_map($rewrite, $actions);

            if ($rewritten !== $actions) {
                DB::table('automations')->where('id', $rule->id)->update(['actions' => json_encode($rewritten)]);
            }
        }
    }
};
