<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('cache:prune-expired')]
#[Description('Removes expired entries from the database cache, which only drops an entry when it is read again')]
class PruneExpiredCache extends Command
{
    public function handle(): int
    {
        $store = config('cache.stores.database');

        if (config('cache.default') !== 'database') {
            $this->components->info('The cache does not use the database, nothing to remove.');

            return self::SUCCESS;
        }

        $deleted = DB::connection($store['connection'] ?? null)
            ->table($store['table'])
            ->where('expiration', '<=', now()->timestamp)
            ->delete();

        $this->components->info("{$deleted} expired cache entries removed.");

        return self::SUCCESS;
    }
}
