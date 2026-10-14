<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('asana:align-subtasks {--dry-run : Only report what would change}')]
#[Description('Moves open subtasks taken over from Asana into the status of their task (as the import now does for new ones)')]
class AlignAsanaSubtasks extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $moved = [];

        // Parents are older than their subtasks, so with the ids in order a subtask sees the new status of its task
        $imported = DB::table('asana_imports')->where('kind', 'task')->pluck('model_id')->map(fn (mixed $id) => (int) $id)->all();

        Task::query()
            ->whereIn('id', $imported)
            ->whereNotNull('parent_id')
            ->with('status')
            ->orderBy('id')
            ->each(function (Task $subtask) use ($dryRun, &$moved): void {
                $project = Project::query()->find($subtask->project_id);
                $parentStatusId = Task::query()->whereKey($subtask->parent_id)->value('status_id');
                $parentStatus = $parentStatusId === null ? null : $project?->statuses()->find($parentStatusId);

                // Only open subtasks that were left at the start, under a task that is not done and stands elsewhere
                if (! $project instanceof Project || $subtask->status?->is_done !== false || $subtask->status_id !== $project->defaultStatus()->id || $parentStatus === null || $parentStatus->is_done || $parentStatus->id === $subtask->status_id) {
                    return;
                }

                $moved[$project->name] = ($moved[$project->name] ?? 0) + 1;

                if (! $dryRun) {
                    // Without events: nobody is notified and no automation runs for a status the import set
                    Task::query()->whereKey($subtask->id)->toBase()->update(['status_id' => $parentStatus->id]);
                }
            });

        foreach ($moved as $name => $count) {
            $this->components->twoColumnDetail($name, (string) $count);
        }

        $this->components->info(($dryRun ? 'Dry run: ' : '').array_sum($moved).' subtasks '.($dryRun ? 'would move' : 'moved').' to the status of their task.');

        return self::SUCCESS;
    }
}
