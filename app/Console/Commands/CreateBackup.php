<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

#[Signature('sprint:backup {--keep= : Days to keep backups (default: BACKUP_KEEP_DAYS)}')]
#[Description('Writes a backup of the database and the attachments and removes old backups')]
class CreateBackup extends Command
{
    public function handle(BackupService $backups): int
    {
        try {
            $backup = $backups->create();
        } catch (Throwable $e) {
            $backups->rememberFailure($e);
            $this->components->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('%s written to the "%s" disk (%s).', $backup['file'], config('sprint.backup.disk'), Number::fileSize($backup['bytes'], precision: 1)));

        if (! $backup['database']) {
            $this->components->warn('The database is not SQLite and was not included; back it up with mysqldump or pg_dump (see docs/maintenance.md).');
        }

        if ($backup['attachments'] === null) {
            $this->components->warn('Attachments are in a bucket and were not included; use the versioning or replication of the provider.');
        } else {
            $this->components->info("{$backup['attachments']} attachment file(s) included.");
        }

        $keepDays = (int) ($this->option('keep') ?? config('sprint.backup.keep_days'));
        $removed = $backups->prune(max(1, $keepDays));
        $this->components->info("{$removed} backup(s) older than {$keepDays} days removed.");

        return self::SUCCESS;
    }
}
