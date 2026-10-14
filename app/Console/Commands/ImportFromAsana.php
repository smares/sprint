<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Services\AsanaClient;
use App\Services\AsanaException;
use App\Services\AsanaImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

#[Signature('asana:import
    {projects?* : Asana project ids to import, in this order (a task in several of them goes to the first); GID=ID imports into the Sprint project with that id instead of creating one}
    {--list : Show the workspaces and projects the token can reach and stop}
    {--token= : Asana personal access token (better: ASANA_TOKEN in .env, or leave it out to be asked)}
    {--as= : Email of the person recorded as the one who imported (default: the first administrator)}
    {--sections=status : What Asana sections become: status (statuses of the project) or headings (headings in the list)}
    {--create-users : Create accounts for people from Asana who have none here (no email is sent)}
    {--ignore-missing-users : Go on although people from Asana have no account here; their assignments are left out}
    {--skip-followers : Do not take over followers as collaborators}
    {--dry-run : Only read from Asana and report what would be imported}')]
#[Description('Imports projects from Asana: tasks, subtasks, people, dependencies, comments and attachments')]
class ImportFromAsana extends Command
{
    /** How many warnings are printed in full. */
    private const int WARNINGS_SHOWN = 40;

    public function handle(): int
    {
        $token = $this->option('token') ?: config('services.asana.token') ?: ($this->input->isInteractive() ? $this->secret('Asana personal access token') : null);

        if (! $token) {
            $this->components->error('No token: set ASANA_TOKEN in .env, pass --token or run the command interactively.');

            return self::FAILURE;
        }

        $service = new AsanaImportService(new AsanaClient((string) $token));

        try {
            return $this->option('list') ? $this->listProjects($service) : $this->import($service);
        } catch (AsanaException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function listProjects(AsanaImportService $service): int
    {
        foreach ($service->projects() as $workspace) {
            $this->components->info('Workspace: '.$workspace['workspace']);
            $this->table(['Asana id', 'Project'], array_map(fn (array $project) => [$project['gid'], $project['name']], $workspace['projects']));
        }

        return self::SUCCESS;
    }

    private function import(AsanaImportService $service): int
    {
        $targets = $this->targets();
        $importer = $this->importer();
        $sections = (string) $this->option('sections');

        if ($targets === [] || ! $importer instanceof User || ! in_array($sections, ['status', 'headings'], true)) {
            $this->components->error($targets === [] ? 'Name at least one Asana project (see --list).' : ($importer instanceof User ? '--sections is status or headings.' : 'No such person for --as (and no administrator to fall back on).'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = $service->run($targets, $importer, [
            'sections' => $sections,
            'create_users' => (bool) $this->option('create-users'),
            'ignore_missing_users' => (bool) $this->option('ignore-missing-users'),
            'followers' => ! $this->option('skip-followers'),
            'dry_run' => $dryRun,
        ], fn (string $message) => $this->components->info($message));

        $this->report($report, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array{counts: array<string, int>, warnings: list<string>, missing: list<array{gid: string, name: string, email: ?string, needed: int}>}  $report
     */
    private function report(array $report, bool $dryRun): void
    {
        $counts = $report['counts'];

        $this->newLine();
        $this->components->info($dryRun ? 'Dry run: nothing was changed. This would be imported:' : 'Imported:');

        foreach ([
            'Projects' => $counts['projects'],
            'Tasks (of them new)' => "{$counts['tasks']} ({$counts['tasks_new']})",
            'Subtasks' => $counts['subtasks'],
            'New comments' => $counts['comments_new'],
            'New attachments' => $counts['attachments_new'].' ('.Number::fileSize($counts['attachment_bytes']).')',
            'Dependencies' => $counts['dependencies'],
            'Left out, as they lie in another project' => $counts['tasks_elsewhere'],
            'Comments by people without an account (name in the text)' => $counts['comments_unknown_author'],
        ] as $label => $value) {
            $this->components->twoColumnDetail($label, (string) $value);
        }

        if ($report['missing'] !== []) {
            $this->newLine();
            $this->components->warn('People in Asana without an account here (matched by email, then by name):');
            $this->table(['Name', 'Email', 'Assignments and memberships'], array_map(fn (array $person) => [$person['name'], $person['email'] ?? '–', $person['needed']], $report['missing']));
        }

        foreach (array_slice($report['warnings'], 0, self::WARNINGS_SHOWN) as $warning) {
            $this->components->warn($warning);
        }

        if (count($report['warnings']) > self::WARNINGS_SHOWN) {
            $this->components->warn((count($report['warnings']) - self::WARNINGS_SHOWN).' more warnings.');
        }
    }

    /**
     * @return list<array{gid: string, project_id: ?int}>
     */
    private function targets(): array
    {
        $targets = [];

        foreach ((array) $this->argument('projects') as $argument) {
            [$gid, $id] = array_pad(explode('=', (string) $argument, 2), 2, null);

            if (! ctype_digit((string) $gid) || ($id !== null && (! ctype_digit($id) || ! Project::query()->whereKey($id)->exists()))) {
                $this->components->error("“{$argument}” is not an Asana project id (or GID=ID with an existing project here).");

                return [];
            }

            $targets[] = ['gid' => (string) $gid, 'project_id' => $id === null ? null : (int) $id];
        }

        return $targets;
    }

    private function importer(): ?User
    {
        $email = $this->option('as');

        return $email
            ? User::query()->active()->where('email', $email)->first()
            : User::query()->active()->where('is_admin', true)->orderBy('id')->first();
    }
}
