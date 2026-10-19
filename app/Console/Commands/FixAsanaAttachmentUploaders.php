<?php

namespace App\Console\Commands;

use App\Services\AsanaClient;
use App\Services\AsanaException;
use App\Services\AsanaImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('asana:fix-attachment-uploaders
    {--token= : Asana personal access token (better: ASANA_TOKEN in .env, or leave it out to be asked)}
    {--dry-run : Only report what would change}')]
#[Description('Credits the files taken over from Asana to the people who uploaded them there (the import now does; before, the importer stood there for all)')]
class FixAsanaAttachmentUploaders extends Command
{
    public function handle(): int
    {
        $token = $this->option('token') ?: config('services.asana.token') ?: getenv('ASANA_TOKEN') ?: ($this->input->isInteractive() ? $this->secret('Asana personal access token') : null);

        if (! $token) {
            $this->components->error('No token: set ASANA_TOKEN (in .env or the environment), pass --token or run the command interactively.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = (new AsanaImportService(new AsanaClient((string) $token)))->fixAttachmentUploaders($dryRun);
        } catch (AsanaException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(($dryRun ? 'Dry run: ' : '')."{$result['checked']} files checked, {$result['changed']} ".($dryRun ? 'would be' : 'were').' credited to their uploader; '."{$result['unknown']} stay as they are (uploader without an account here, or unknown to Asana).");

        return self::SUCCESS;
    }
}
