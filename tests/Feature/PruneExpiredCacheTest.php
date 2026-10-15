<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneExpiredCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_entries_are_removed_and_live_ones_stay(): void
    {
        config(['cache.default' => 'database']);

        Cache::put('alt', 'x', 60);
        Cache::put('neu', 'y', 3600);
        DB::table('cache')->where('key', 'like', '%alt')->update(['expiration' => now()->subMinute()->timestamp]);

        $this->artisan('cache:prune-expired')->assertSuccessful();

        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%alt')->count());
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%neu')->count());
    }

    public function test_it_does_nothing_for_another_cache_store(): void
    {
        config(['cache.default' => 'array']);

        $this->artisan('cache:prune-expired')->expectsOutputToContain('nothing to remove')->assertSuccessful();
    }

    public function test_it_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command ?? '', 'cache:prune-expired')));
    }
}
