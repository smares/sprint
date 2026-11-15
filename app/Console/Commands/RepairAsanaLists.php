<?php

namespace App\Console\Commands;

use App\Services\AsanaClient;
use App\Services\AsanaException;
use App\Services\AsanaImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('asana:repair-lists
    {projects?* : Ids of the projects here to repair; without any, all projects with imported tasks}
    {--token= : Asana personal access token (better: ASANA_TOKEN in .env, or leave it out to be asked)}
    {--dry-run : Only read from Asana and report what would be repaired}')]
#[Description('Puts back the sub-lists in imported descriptions and comments that older imports dropped')]
class RepairAsanaLists extends Command
{
    public function handle(): int
    {
        // The configuration may be cached (as in the Docker image), then it does not know a token passed to a running container
        $token = $this->option('token') ?: config('services.asana.token') ?: getenv('ASANA_TOKEN') ?: ($this->input->isInteractive() ? $this->secret('Asana personal access token') : null);

        if (! $token) {
            $this->components->error('No token: set ASANA_TOKEN (in .env or the environment), pass --token or run the command interactively.');

            return self::FAILURE;
        }

        $projects = (array) $this->argument('projects');

        if ($projects !== [] && array_filter($projects, fn (mixed $id) => ! ctype_digit((string) $id)) !== []) {
            $this->components->error('The projects are given by their ids here (numbers).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $service = new AsanaImportService(new AsanaClient((string) $token));

        try {
            $report = $service->repairSubLists(array_map('intval', $projects), $dryRun, $this->line(...), $this->progress(...));
        } catch (AsanaException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info($dryRun ? 'Dry run: nothing was changed. This would be repaired:' : 'Repaired:');
        $this->components->twoColumnDetail('Descriptions', (string) $report['tasks']);
        $this->components->twoColumnDetail('Comments', (string) $report['comments']);

        foreach ($report['skipped'] as $note) {
            $this->components->warn($note);
        }

        return self::SUCCESS;
    }

    /**
     * Without a terminal (logs, pipes) every this many tasks a line says how far it is.
     */
    private function progress(string $phase, int $done, ?int $total): void
    {
        if ($done > 0 && $done % 25 === 0) {
            $this->line("{$done} of {$total} tasks looked at …");
        }
    }
}
