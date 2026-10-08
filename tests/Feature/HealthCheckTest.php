<?php

namespace Tests\Feature;

use App\HealthCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(?int $minutesAgo): void
    {
        $minutesAgo === null
            ? Cache::forget(HealthCheck::SCHEDULER_HEARTBEAT)
            : Cache::put(HealthCheck::SCHEDULER_HEARTBEAT, now()->subMinutes($minutesAgo)->timestamp, 3600);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    private function job(int $availableMinutesAgo, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservedAt,
            'available_at' => now()->subMinutes($availableMinutesAgo)->timestamp, 'created_at' => now()->timestamp,
        ]);
    }

    public function test_a_healthy_installation_answers_ok_without_logging_in(): void
    {
        $this->heartbeat(0);

        $this->getJson(route('health'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'storage' => 'ok', 'cache' => 'ok', 'scheduler' => 'ok', 'queue' => 'ok']]);
    }

    public function test_a_missing_scheduler_is_only_a_warning(): void
    {
        $this->heartbeat(null);

        $this->getJson(route('health'))->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.scheduler', 'warn');

        $this->heartbeat(HealthCheck::STALE_MINUTES + 1);
        $this->getJson(route('health'))->assertOk()->assertJsonPath('checks.scheduler', 'warn');

        $this->heartbeat(HealthCheck::STALE_MINUTES - 1);
        $this->getJson(route('health'))->assertJsonPath('checks.scheduler', 'ok');
    }

    public function test_jobs_that_wait_for_a_long_time_point_to_a_missing_worker(): void
    {
        $this->heartbeat(0);
        $this->job(1);
        $this->getJson(route('health'))->assertJsonPath('checks.queue', 'ok');

        $this->job(HealthCheck::STALE_MINUTES + 1);
        $this->getJson(route('health'))->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.queue', 'warn');
    }

    public function test_jobs_that_are_being_worked_on_do_not_count_as_waiting(): void
    {
        $this->heartbeat(0);
        $this->job(30, now()->timestamp);

        $this->getJson(route('health'))->assertJsonPath('checks.queue', 'ok');
    }

    public function test_failed_jobs_are_a_warning(): void
    {
        $this->heartbeat(0);
        DB::table('failed_jobs')->insert(['uuid' => 'abc', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $this->getJson(route('health'))->assertOk()->assertJsonPath('checks.queue', 'warn');
    }

    public function test_a_broken_essential_part_makes_the_installation_count_as_down(): void
    {
        $this->partialMock(HealthCheck::class, fn ($mock) => $mock->shouldAllowMockingProtectedMethods()
            ->shouldReceive('database')->andReturn(['status' => HealthCheck::FAIL, 'detail' => 'weg']));

        $this->getJson(route('health'))->assertStatus(503)->assertJsonPath('status', 'down')->assertJsonPath('checks.database', 'fail');
        $this->artisan('sprint:health')->expectsOutputToContain('Overall: down')->assertFailed();
    }

    public function test_a_failing_storage_or_cache_is_down_too(): void
    {
        foreach (['storage', 'cache'] as $part) {
            $this->partialMock(HealthCheck::class, fn ($mock) => $mock->shouldAllowMockingProtectedMethods()
                ->shouldReceive($part)->andReturn(['status' => HealthCheck::FAIL, 'detail' => 'weg']));

            $this->getJson(route('health'))->assertStatus(503)->assertJsonPath("checks.{$part}", 'fail');
        }
    }

    public function test_the_details_never_appear_in_the_public_answer(): void
    {
        $this->heartbeat(0);

        $response = $this->getJson(route('health'))->assertOk();

        $this->assertStringNotContainsString('storage/app', $response->getContent());
        $this->assertStringNotContainsString('sqlite', $response->getContent());
    }

    public function test_the_command_prints_every_check_and_fails_only_when_down(): void
    {
        $this->heartbeat(0);

        $this->artisan('sprint:health')->expectsOutputToContain('database')->expectsOutputToContain('scheduler')->expectsOutputToContain('Overall: ok')->assertSuccessful();

        $this->heartbeat(null);
        $this->artisan('sprint:health')->expectsOutputToContain('Overall: degraded')->assertSuccessful();
    }

    public function test_the_scheduler_stamps_the_heartbeat_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'scheduler-heartbeat');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        Cache::forget(HealthCheck::SCHEDULER_HEARTBEAT);
        $this->artisan('schedule:run');
        $this->assertNotNull(Cache::get(HealthCheck::SCHEDULER_HEARTBEAT));
    }
}
