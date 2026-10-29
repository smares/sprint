<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Is the installation able to do its job? Database, storage and cache are essential; a missing scheduler
 * or queue worker does not stop the pages but silently stops mails and the daily digest, so it is a warning.
 */
class HealthCheckService
{
    public const SCHEDULER_HEARTBEAT = 'sprint:scheduler-heartbeat';

    /** Minutes after which a silent scheduler or an unprocessed job counts as stuck. */
    public const STALE_MINUTES = 5;

    /** Hours a failed job keeps counting as a warning. */
    public const FAILED_HOURS = 24;

    /** Hours after which the last successful backup counts as too old (a nightly run plus a day of slack). */
    public const BACKUP_STALE_HOURS = 48;

    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @return array<string, array{status: string, detail: string}>
     */
    public function run(): array
    {
        return [
            'database' => $this->database(),
            'storage' => $this->storage(),
            'cache' => $this->cache(),
            'scheduler' => $this->scheduler(),
            'queue' => $this->queue(),
            'backup' => $this->backup(),
        ];
    }

    /**
     * ok when everything runs; degraded when only warnings are left; down when something essential fails.
     *
     * @param  array<string, array{status: string, detail: string}>  $checks
     */
    public function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::FAIL, $statuses, true) => 'down',
            in_array(self::WARN, $statuses, true) => 'degraded',
            default => 'ok',
        };
    }

    /**
     * @return array{status: string, detail: string}
     */
    protected function database(): array
    {
        try {
            DB::select('select 1');

            return $this->result(self::OK, DB::getDriverName());
        } catch (Throwable $e) {
            return $this->result(self::FAIL, $e->getMessage());
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    protected function storage(): array
    {
        try {
            $disk = Storage::disk();
            $disk->put('.health', (string) now()->timestamp);
            $disk->delete('.health');

            return $this->result(self::OK, sprintf('Attachment storage (%s) is writable', config('filesystems.default')));
        } catch (Throwable $e) {
            return $this->result(self::FAIL, $e->getMessage());
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    protected function cache(): array
    {
        try {
            $key = 'sprint:health-probe';
            Cache::put($key, 'ok', 10);

            return Cache::get($key) === 'ok'
                ? $this->result(self::OK, config('cache.default'))
                : $this->result(self::FAIL, 'The cache does not keep values.');
        } catch (Throwable $e) {
            return $this->result(self::FAIL, $e->getMessage());
        }
    }

    /**
     * The scheduler stamps the cache every minute (see routes/console.php).
     *
     * @return array{status: string, detail: string}
     */
    protected function scheduler(): array
    {
        try {
            $last = Cache::get(self::SCHEDULER_HEARTBEAT);
        } catch (Throwable) {
            return $this->result(self::WARN, 'Heartbeat not readable.');
        }

        if ($last === null) {
            return $this->result(self::WARN, 'The scheduler has never run; the cron entry that starts `php artisan schedule:run` every minute is missing.');
        }

        $minutes = (int) floor((now()->timestamp - (int) $last) / 60);

        return $minutes >= self::STALE_MINUTES
            ? $this->result(self::WARN, sprintf('The scheduler last ran %d minutes ago.', $minutes))
            : $this->result(self::OK, $minutes === 0 ? 'last ran just now' : sprintf('last ran %d min. ago', $minutes));
    }

    /**
     * With the database queue, a job that has been waiting for minutes means no worker is running. Failed jobs only count for a
     * day, so an old one that nobody cleared does not keep the installation on "degraded" for good.
     *
     * @return array{status: string, detail: string}
     */
    protected function queue(): array
    {
        $connection = config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return $this->result(self::OK, sprintf('%s (not checked)', $connection));
        }

        try {
            $table = config("queue.connections.{$connection}.table", 'jobs');
            $waiting = DB::table($table)
                ->where('available_at', '<=', now()->subMinutes(self::STALE_MINUTES)->timestamp)
                ->whereNull('reserved_at')
                ->count();
            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->where('failed_at', '>=', now()->subHours(self::FAILED_HOURS))->count();
        } catch (Throwable $e) {
            return $this->result(self::WARN, $e->getMessage());
        }

        return match (true) {
            $waiting > 0 => $this->result(self::WARN, sprintf('%d jobs have been waiting for more than %d minutes; is `php artisan queue:work` running?', $waiting, self::STALE_MINUTES)),
            $failed > 0 => $this->result(self::WARN, sprintf('%s in the last %d hours, see `php artisan queue:failed`.', $failed === 1 ? 'One failed job' : "{$failed} failed jobs", self::FAILED_HOURS)),
            default => $this->result(self::OK, 'no backlog'),
        };
    }

    /**
     * The nightly backup (see BackupService); a failed or missing one does not stop the pages, so it is a warning.
     *
     * @return array{status: string, detail: string}
     */
    protected function backup(): array
    {
        if (! config('sprint.backup.enabled')) {
            return $this->result(self::OK, 'turned off');
        }

        try {
            $last = app(BackupService::class)->lastRun();
        } catch (Throwable) {
            return $this->result(self::WARN, 'Last backup not readable.');
        }

        if ($last === null) {
            return $this->result(self::OK, 'no backup yet');
        }

        if (! $last['ok']) {
            return $this->result(self::WARN, 'The last backup failed: '.$last['error']);
        }

        $hours = (int) floor((now()->timestamp - $last['at']) / 3600);

        return $hours >= self::BACKUP_STALE_HOURS
            ? $this->result(self::WARN, sprintf('The last backup is %d hours old.', $hours))
            : $this->result(self::OK, sprintf('%s, %d h ago', $last['file'], $hours));
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function result(string $status, string $detail): array
    {
        return ['status' => $status, 'detail' => $detail];
    }
}
