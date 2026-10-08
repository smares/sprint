<?php

namespace App;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Is the installation able to do its job? Database, storage and cache are essential; a missing scheduler
 * or queue worker does not stop the pages but silently stops mails and the daily digest, so it is a warning.
 */
class HealthCheck
{
    public const SCHEDULER_HEARTBEAT = 'sprint:scheduler-heartbeat';

    /** Minutes after which a silent scheduler or an unprocessed job counts as stuck. */
    public const STALE_MINUTES = 5;

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
        ];
    }

    /**
     * ok, wenn alles läuft; degraded, wenn nur Warnungen übrig sind; down, wenn etwas Wesentliches ausfällt.
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

            return $this->result(self::OK, __('Attachment storage (:disk) is writable', ['disk' => config('filesystems.default')]));
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
                : $this->result(self::FAIL, __('The cache does not keep values.'));
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
            return $this->result(self::WARN, __('Heartbeat not readable.'));
        }

        if ($last === null) {
            return $this->result(self::WARN, __('The scheduler has never run; the cron entry that starts `php artisan schedule:run` every minute is missing.'));
        }

        $minutes = (int) floor((now()->timestamp - (int) $last) / 60);

        return $minutes >= self::STALE_MINUTES
            ? $this->result(self::WARN, __('The scheduler last ran :minutes minutes ago.', ['minutes' => $minutes]))
            : $this->result(self::OK, $minutes === 0 ? __('last ran just now') : __('last ran :minutes min. ago', ['minutes' => $minutes]));
    }

    /**
     * With the database queue, a job that has been waiting for minutes means no worker is running.
     *
     * @return array{status: string, detail: string}
     */
    protected function queue(): array
    {
        $connection = config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return $this->result(self::OK, __(':connection (not checked)', ['connection' => $connection]));
        }

        try {
            $table = config("queue.connections.{$connection}.table", 'jobs');
            $waiting = DB::table($table)
                ->where('available_at', '<=', now()->subMinutes(self::STALE_MINUTES)->timestamp)
                ->whereNull('reserved_at')
                ->count();
            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable $e) {
            return $this->result(self::WARN, $e->getMessage());
        }

        return match (true) {
            $waiting > 0 => $this->result(self::WARN, __(':waiting jobs have been waiting for more than :minutes minutes; is `php artisan queue:work` running?', ['waiting' => $waiting, 'minutes' => self::STALE_MINUTES])),
            $failed > 0 => $this->result(self::WARN, __(':failed failed jobs, see `php artisan queue:failed`.', ['failed' => $failed])),
            default => $this->result(self::OK, __('no backlog')),
        };
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function result(string $status, string $detail): array
    {
        return ['status' => $status, 'detail' => $detail];
    }
}
