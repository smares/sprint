<?php

namespace App\Console\Commands;

use App\Services\AsanaClient;
use App\Services\AsanaException;
use App\Services\AsanaImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

#[Signature('asana:repair-lists
    {projects?* : Ids of the projects here to repair; without any, all projects with imported tasks}
    {--token= : Asana personal access token (better: ASANA_TOKEN in .env, or leave it out to be asked)}
    {--dry-run : Only read from Asana and report what would be repaired}')]
#[Description('Puts back the sub-lists in imported descriptions and comments that older imports dropped')]
class RepairAsanaLists extends Command
{
    private ?ProgressBar $bar = null;

    private int $reportedLine = 0;

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
        $client->whenPausing(fn (int $seconds) => $this->pausing($seconds));
        $service = new AsanaImportService($client);

        try {
            $report = $service->repairSubLists(array_map('intval', $projects), $dryRun, $this->message(...), $this->progress(...));
        } catch (AsanaException $exception) {
            $this->finishProgress();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->finishProgress();
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
     * A line about a text that is repaired, printed without breaking a bar that is running.
     */
    private function message(string $message): void
    {
        $this->bar?->clear();
        $this->components->info($message);
        $this->bar?->display();
    }

    /**
     * With a terminal a bar shows how many tasks are looked at; without one (logs, pipes) a line says it every few tasks.
     */
    private function progress(string $phase, int $done, ?int $total): void
    {
        if ($this->output->isDecorated()) {
            if (! $this->bar instanceof ProgressBar) {
                $this->bar = $this->output->createProgressBar($total ?? 0);
                $this->bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s% (about %estimated:-6s% in all)  %message%');
                $this->bar->setMessage('');
                $this->bar->start();
            }

            $this->bar->setMessage('');
            $this->bar->setProgress($done);
        } elseif ($done - $this->reportedLine >= 25) {
            $this->reportedLine = $done;
            $this->line("{$done} of {$total} tasks looked at …");
        }
    }

    /**
     * Asana asked to wait (or could not be reached): say it, or the command looks as if it hangs.
     */
    private function pausing(int $seconds): void
    {
        $note = "waiting {$seconds} s for Asana (rate limit or a temporary error)";

        if ($this->bar instanceof ProgressBar) {
            $this->bar->setMessage($note);
            $this->bar->display();
        } else {
            $this->line("… {$note}");
        }
    }

    private function finishProgress(): void
    {
        if ($this->bar instanceof ProgressBar) {
            $this->bar->finish();
            $this->newLine();
        }

        $this->bar = null;
    }
}
