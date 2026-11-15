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
    /** How many of the texts left as they are are printed. */
    private const int NOTES_SHOWN = 40;

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
        $client = new AsanaClient((string) $token);
        $client->whenPausing(fn (int $seconds) => $this->line("… waiting {$seconds} s for Asana (rate limit or a temporary error)"));
        $service = new AsanaImportService($client);
        $totals = ['descriptions' => 0, 'comments' => 0];
        $skipped = [];
        $check = [];

        try {
            $tasks = $service->repairableTasks(array_map(intval(...), $projects));
            $this->components->info(count($tasks).' imported tasks to look at.');

            $this->withProgressBar($tasks, function (array $task) use ($service, $dryRun, &$totals, &$skipped, &$check): void {
                $result = $service->repairTask($task['gid'], $task['id'], $dryRun);
                $totals['descriptions'] += $result['descriptions'];
                $totals['comments'] += $result['comments'];
                array_push($skipped, ...$result['skipped']);
                array_push($check, ...$result['check']);
            });
            $this->newLine(2);
        } catch (AsanaException $exception) {
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($dryRun ? 'Dry run: nothing was changed. This would be repaired:' : 'Repaired:');
        $this->components->twoColumnDetail('Descriptions', (string) $totals['descriptions']);
        $this->components->twoColumnDetail('Comments', (string) $totals['comments']);

        if ($check !== []) {
            $this->components->info(($dryRun ? 'Would be repaired' : 'Repaired').', but changed here before, please look at them:');

            foreach (array_slice($check, 0, self::NOTES_SHOWN) as $note) {
                $this->components->warn($note);
            }

            if (count($check) > self::NOTES_SHOWN) {
                $this->components->warn((count($check) - self::NOTES_SHOWN).' more.');
            }
        }

        foreach (array_slice($skipped, 0, self::NOTES_SHOWN) as $note) {
            $this->components->warn($note);
        }

        if (count($skipped) > self::NOTES_SHOWN) {
            $this->components->warn((count($skipped) - self::NOTES_SHOWN).' more texts left as they are.');
        }

        return self::SUCCESS;
    }
}
