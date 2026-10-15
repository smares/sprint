<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Models\Task;
use App\Models\TaskActivity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('asana:fix-created-history {--dry-run : Only report what would change}')]
#[Description('Dates the "created the task" entry of tasks taken over from Asana by their creation in Asana and credits its creator (as the import now does)')]
class FixAsanaCreatedHistory extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        // The task keeps the time and the creator from Asana; only its history entry carries the day of the import
        $imported = DB::table('asana_imports')->where('kind', 'task')->select('model_id');

        Task::query()
            ->whereIn('id', $imported)
            ->orderBy('id')
            ->chunkById(500, function ($tasks) use ($dryRun, &$fixed): void {
                $entries = TaskActivity::query()
                    ->whereIn('task_id', $tasks->modelKeys())
                    ->where('type', ActivityType::Created)
                    ->get()
                    ->groupBy('task_id');

                foreach ($tasks as $task) {
                    foreach ($entries->get($task->id, []) as $entry) {
                        if ($entry->user_id === $task->creator_id && $entry->created_at?->equalTo($task->created_at)) {
                            continue;
                        }

                        $fixed++;

                        if (! $dryRun) {
                            TaskActivity::query()->whereKey($entry->id)->toBase()->update(['user_id' => $task->creator_id, 'created_at' => $task->created_at]);
                        }
                    }
                }
            });

        $this->components->info(($dryRun ? 'Dry run: ' : '')."{$fixed} history entries ".($dryRun ? 'would be' : 'were').' dated by their creation in Asana.');

        return self::SUCCESS;
    }
}
