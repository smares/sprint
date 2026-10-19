<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AsanaClient;
use App\Services\AsanaException;
use App\Services\AsanaImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

#[Signature('asana:import-linked-attachments
    {--token= : Asana personal access token (better: ASANA_TOKEN in .env, or leave it out to be asked)}
    {--as= : Email of the person credited where the uploader in Asana has no account here (default: the first administrator)}
    {--dry-run : Only report what would be fetched}')]
#[Description('Fetches the files from Asana that earlier imports only linked (over the former limit of 20 MB) and attaches them to their tasks')]
class ImportLinkedAsanaAttachments extends Command
{
    public function handle(): int
    {
        $token = $this->option('token') ?: config('services.asana.token') ?: getenv('ASANA_TOKEN') ?: ($this->input->isInteractive() ? $this->secret('Asana personal access token') : null);

        if (! $token) {
            $this->components->error('No token: set ASANA_TOKEN (in .env or the environment), pass --token or run the command interactively.');

            return self::FAILURE;
        }

        $email = $this->option('as');
        $importer = $email
            ? User::query()->active()->where('email', $email)->first()
            : User::query()->active()->where('is_admin', true)->orderBy('id')->first();

        if (! $importer instanceof User) {
            $this->components->error($email ? "No active account with the address {$email}." : 'There is no active administrator; name a person with --as.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $lastLine = 0;

        try {
            $result = (new AsanaImportService(new AsanaClient((string) $token)))->importLinkedAttachments($importer, $dryRun, function (int $done, int $total) use (&$lastLine): void {
                if ($done - $lastLine >= 10 || $done === $total) {
                    $lastLine = $done;
                    $this->line("{$done} of {$total} links looked at …");
                }
            });
        } catch (AsanaException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach (array_slice($result['warnings'], 0, 40) as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info(($dryRun ? 'Dry run: ' : '')."{$result['checked']} links checked, {$result['imported']} ".($dryRun ? 'would be' : 'were').' attached ('.Number::fileSize($result['bytes'])."); {$result['elsewhere']} are files of other services and stay links, {$result['failed']} could not be fetched.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
