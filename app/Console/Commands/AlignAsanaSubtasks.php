<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('asana:align-subtasks {--dry-run : Only report what would change}')]
#[Description('Moves open subtasks taken over from Asana into the status of their task (as the import now does for new ones)')]
class AlignAsanaSubtasks extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $moved = [];

        /** @var array<int, array{name: string, start: int, statuses: Collection<int, TaskStatus>}|null> $projects */
        $projects = [];

        /** @var array<int, int> $planned The status a subtask moves to; kept in a dry run too, so a subtask of it sees it. */
        $planned = [];

        // Parents are older than their subtasks, so with the ids in order a subtask sees the new status of its task
        $imported = DB::table('asana_imports')->where('kind', 'task')->select('model_id');

        Task::query()
            ->whereIn('id', $imported)
            ->whereNotNull('parent_id')
            ->with(['status', 'parent:id,status_id'])
            ->orderBy('id')
            ->each(function (Task $subtask) use ($dryRun, &$moved, &$projects, &$planned): void {
                $project = $projects[$subtask->project_id] ??= $this->describe($subtask->project_id);

                if ($project === null) {
                    return;
                }

                $parentStatusId = $planned[$subtask->parent_id] ?? $subtask->parent?->status_id;
                $parentStatus = $parentStatusId === null ? null : $project['statuses']->get($parentStatusId);

                // Only open subtasks that were left at the start, under a task that is not done and stands elsewhere
                if ($subtask->status?->is_done !== false || $subtask->status_id !== $project['start'] || $parentStatus === null || $parentStatus->is_done || $parentStatus->id === $subtask->status_id) {
                    return;
                }

                $moved[$project['name']] = ($moved[$project['name']] ?? 0) + 1;
                $planned[$subtask->id] = $parentStatus->id;

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

    /**
     * What is needed to judge the subtasks of a project, read once per project.
     *
     * @return array{name: string, start: int, statuses: Collection<int, TaskStatus>}|null
     */
    private function describe(int $projectId): ?array
    {
        $project = Project::query()->find($projectId);

        return $project instanceof Project
            ? ['name' => $project->name, 'start' => $project->defaultStatus()->id, 'statuses' => $project->statuses()->get()->keyBy('id')]
            : null;
    }
}
