<?php

namespace App\Console\Commands;

use App\Services\TaskSearch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:rebuild')]
#[Description('Rebuilds the task search index (SQLite with FTS5 only)')]
class RebuildSearchIndex extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TaskSearch $search): int
    {
        if (! $search->usesFullText() && ! $search->createIndexTable()) {
            $this->components->info('FTS5 is not available; the search uses LIKE and needs no index.');

            return self::SUCCESS;
        }

        $this->components->info($search->rebuild().' tasks indexed.');

        return self::SUCCESS;
    }
}
