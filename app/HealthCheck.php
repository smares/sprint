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
            $disk = Storage::disk('local');
            $disk->put('.health', (string) now()->timestamp);
            $disk->delete('.health');

            return $this->result(self::OK, 'storage/app/private ist beschreibbar');
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
                : $this->result(self::FAIL, 'Der Cache behält keine Werte.');
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
            return $this->result(self::WARN, 'Herzschlag nicht lesbar.');
        }

        if ($last === null) {
            return $this->result(self::WARN, 'Der Scheduler ist noch nie gelaufen; es fehlt der Cron-Eintrag, der jede Minute `php artisan schedule:run` startet.');
        }

        $minutes = (int) floor((now()->timestamp - (int) $last) / 60);

        return $minutes >= self::STALE_MINUTES
            ? $this->result(self::WARN, "Der Scheduler ist zuletzt vor {$minutes} Minuten gelaufen.")
            : $this->result(self::OK, 'zuletzt '.($minutes === 0 ? 'gerade eben' : "vor {$minutes} Min."));
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
            return $this->result(self::OK, "{$connection} (nicht geprüft)");
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
            $waiting > 0 => $this->result(self::WARN, "{$waiting} Jobs warten seit über ".self::STALE_MINUTES.' Minuten; läuft `php artisan queue:work`?'),
            $failed > 0 => $this->result(self::WARN, "{$failed} fehlgeschlagene Jobs, siehe `php artisan queue:failed`."),
            default => $this->result(self::OK, 'kein Rückstand'),
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
