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
        $doneStatusIds = DB::table('task_statuses')->where('is_done', true)->pluck('id')->all();

        if ($doneStatusIds !== []) {
            DB::table('tasks')->whereIn('status_id', $doneStatusIds)->whereNull('completed_at')->update(['completed_at' => now()]);

            DB::table('automations')->where('trigger', 'status_changed')->whereIn('trigger_value', $doneStatusIds)
                ->update(['trigger' => 'completed', 'trigger_value' => null]);

            foreach (DB::table('automations')->select('id', 'actions')->orderBy('id')->get() as $rule) {
                $actions = json_decode($rule->actions, true) ?: [];
                $changed = false;

                foreach ($actions as &$action) {
                    if (($action['type'] ?? null) === 'set_status' && in_array((int) ($action['value'] ?? 0), $doneStatusIds, true)) {
                        $action = ['type' => 'complete', 'value' => null];
                        $changed = true;
                    }
                }
                unset($action);

                if ($changed) {
                    DB::table('automations')->where('id', $rule->id)->update(['actions' => json_encode($actions)]);
                }
            }
        }

        Schema::table('task_statuses', function (Blueprint $table) {
            $table->dropColumn('is_done');
        });
    }

    /**
     * The last status of each project is the done one again (what a new project had), which is the closest to what was.
     */
    public function down(): void
    {
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->boolean('is_done')->default(false)->after('position');
        });

        foreach (DB::table('task_statuses')->select('project_id')->distinct()->pluck('project_id') as $projectId) {
            $last = DB::table('task_statuses')->where('project_id', $projectId)->orderByDesc('position')->orderByDesc('id')->value('id');
            DB::table('task_statuses')->where('id', $last)->update(['is_done' => true]);
        }
    }
};
