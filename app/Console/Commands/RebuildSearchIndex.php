<?php

namespace App\Console\Commands;

use App\TaskSearch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:rebuild')]
#[Description('Baut den Suchindex für Aufgaben neu auf (nur SQLite mit FTS5)')]
class RebuildSearchIndex extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TaskSearch $search): int
    {
        if (! $search->usesFullText() && ! $search->createIndexTable()) {
            $this->components->info('Kein FTS5 verfügbar – die Suche nutzt LIKE und braucht keinen Index.');

            return self::SUCCESS;
        }

        $this->components->info($search->rebuild().' Aufgaben indiziert.');

        return self::SUCCESS;
    }
}
