<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Nightly backups: one ZIP per run with a consistent copy of the SQLite database and the attachments
 * of a local default disk, written to the backup disk (see config/sprint.php).
 */
class BackupService
{
    /** Cache key with the outcome of the last run, for the health check. */
    public const LAST_RUN = 'sprint:backup-last-run';

    /** Backups are named sprint-YYYY-MM-DD-HHMMSS.zip; nothing else on the disk is ever touched. */
    private const string FILE_PATTERN = '/^sprint-(\d{4}-\d{2}-\d{2}-\d{6})\.zip$/';

    /**
     * @return array{file: string, bytes: int, database: bool, attachments: int|null}
     */
    public function create(): array
    {
        throw_unless(class_exists(ZipArchive::class), RuntimeException::class, 'The PHP extension "zip" is needed for backups.');

        $file = 'sprint-'.now()->format('Y-m-d-His').'.zip';
        $zipPath = tempnam(sys_get_temp_dir(), 'sprint-backup-');
        $databaseCopy = null;

        try {
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::OVERWRITE);

            $databaseCopy = $this->copyDatabase();
            if ($databaseCopy !== null) {
                $zip->addFile($databaseCopy, 'database.sqlite');
            }

            $attachments = $this->addAttachments($zip);

            $zip->close();

            $stream = fopen($zipPath, 'r');
            Storage::disk(config('sprint.backup.disk'))->writeStream($file, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $result = ['file' => $file, 'bytes' => (int) filesize($zipPath), 'database' => $databaseCopy !== null, 'attachments' => $attachments];
        } finally {
            @unlink($zipPath);
            if ($databaseCopy !== null) {
                @unlink($databaseCopy);
            }
        }

        $this->remember(true, $file);

        return $result;
    }

    /**
     * Removes the backups older than the given number of days; returns how many.
     */
    public function prune(int $keepDays): int
    {
        $disk = Storage::disk(config('sprint.backup.disk'));
        $cutoff = now()->subDays($keepDays);
        $removed = 0;

        foreach ($this->backups() as $file => $createdAt) {
            if ($createdAt->lt($cutoff)) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The existing backups, newest first.
     *
     * @return array<string, Carbon>
     */
    public function backups(): array
    {
        $backups = [];

        foreach (Storage::disk(config('sprint.backup.disk'))->files() as $file) {
            if (preg_match(self::FILE_PATTERN, $file, $match) === 1) {
                $backups[$file] = Carbon::createFromFormat('Y-m-d-His', $match[1]);
            }
        }

        arsort($backups);

        return $backups;
    }

    public function rememberFailure(Throwable $e): void
    {
        $this->remember(false, null, $e->getMessage());
    }

    /**
     * @return array{at: int, ok: bool, file: string|null, error: string|null}|null
     */
    public function lastRun(): ?array
    {
        return Cache::get(self::LAST_RUN);
    }

    /**
     * A consistent copy even while the app writes (WAL mode); null for other databases, whose own tools do this better.
     */
    private function copyDatabase(): ?string
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return null;
        }

        $copy = tempnam(sys_get_temp_dir(), 'sprint-db-');
        unlink($copy);
        DB::statement('VACUUM INTO ?', [$copy]);

        return $copy;
    }

    /**
     * Attachments of a local default disk; null for buckets, which the provider versions or replicates.
     */
    private function addAttachments(ZipArchive $zip): ?int
    {
        $diskName = config('filesystems.default');

        if (config("filesystems.disks.{$diskName}.driver") !== 'local') {
            return null;
        }

        $disk = Storage::disk($diskName);
        $count = 0;

        foreach ($disk->allFiles() as $file) {
            // Unfinished uploads, backups written to the same disk and placeholders such as .gitignore stay out
            if (str_starts_with($file, 'livewire-tmp/') || str_starts_with(basename($file), '.') || preg_match(self::FILE_PATTERN, $file) === 1) {
                continue;
            }

            $zip->addFile($disk->path($file), 'attachments/'.$file);
            $count++;
        }

        return $count;
    }

    private function remember(bool $ok, ?string $file, ?string $error = null): void
    {
        Cache::forever(self::LAST_RUN, ['at' => now()->timestamp, 'ok' => $ok, 'file' => $file, 'error' => $error]);
    }
}
