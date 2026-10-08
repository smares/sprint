<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupService;
use App\Services\HealthCheckService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    // SQLite refuses VACUUM INTO inside a transaction, so no RefreshDatabase here
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Storage::fake('local');
        Cache::forget(BackupService::LAST_RUN);
    }

    /**
     * @return array<string, string> file name => contents of the single backup on the backup disk
     */
    private function unzipOnlyBackup(): array
    {
        $files = Storage::disk('backups')->files();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^sprint-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $files[0]);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('backups')->path($files[0]));
        $contents = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $contents[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $contents;
    }

    public function test_a_backup_holds_a_working_copy_of_the_database_and_the_attachments(): void
    {
        User::factory()->create(['email' => 'anna@example.com']);
        Storage::disk('local')->put('attachments/1/2/plan.pdf', 'PDF');
        Storage::disk('local')->put('livewire-tmp/half-uploaded.png', 'PNG');

        $this->artisan('sprint:backup')
            ->expectsOutputToContain('1 attachment file(s) included')
            ->assertSuccessful();

        $contents = $this->unzipOnlyBackup();
        $this->assertSame('PDF', $contents['attachments/attachments/1/2/plan.pdf']);
        $this->assertArrayNotHasKey('attachments/livewire-tmp/half-uploaded.png', $contents);

        $copy = tempnam(sys_get_temp_dir(), 'restored-');
        file_put_contents($copy, $contents['database.sqlite']);
        $emails = (new PDO("sqlite:{$copy}"))->query('select email from users')->fetchAll(PDO::FETCH_COLUMN);
        unlink($copy);
        $this->assertSame(['anna@example.com'], $emails);

        $this->assertTrue(app(BackupService::class)->lastRun()['ok']);
    }

    public function test_attachments_in_a_bucket_are_left_to_the_provider(): void
    {
        config(['filesystems.disks.local.driver' => 's3']);

        $this->artisan('sprint:backup')->expectsOutputToContain('Attachments are in a bucket')->assertSuccessful();

        $this->assertArrayHasKey('database.sqlite', $this->unzipOnlyBackup());
    }

    public function test_old_backups_are_removed_and_nothing_else_is_touched(): void
    {
        $this->travelTo('2026-10-08 02:30:00');
        $disk = Storage::disk('backups');
        $disk->put('sprint-2026-09-20-023000.zip', 'old');
        $disk->put('sprint-2026-10-01-023000.zip', 'recent');
        $disk->put('notes.txt', 'mine');

        $this->artisan('sprint:backup', ['--keep' => 14])->expectsOutputToContain('1 backup(s) older than 14 days removed')->assertSuccessful();

        $this->assertFalse($disk->exists('sprint-2026-09-20-023000.zip'));
        $this->assertTrue($disk->exists('sprint-2026-10-01-023000.zip'));
        $this->assertTrue($disk->exists('sprint-2026-10-08-023000.zip'));
        $this->assertTrue($disk->exists('notes.txt'));
    }

    public function test_it_runs_every_night_unless_turned_off(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'sprint:backup'));

        $this->assertNotNull($event);
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app));

        config(['sprint.backup.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_the_health_check_warns_about_failed_and_old_backups(): void
    {
        $backup = fn () => app(HealthCheckService::class)->run()['backup'];

        $this->assertSame('ok', $backup()['status']);

        $this->artisan('sprint:backup')->assertSuccessful();
        $this->assertSame('ok', $backup()['status']);

        $this->travel(HealthCheckService::BACKUP_STALE_HOURS + 1)->hours();
        $this->assertSame('warn', $backup()['status']);

        app(BackupService::class)->rememberFailure(new RuntimeException('disk full'));
        $this->assertSame('warn', $backup()['status']);
        $this->assertStringContainsString('disk full', $backup()['detail']);

        config(['sprint.backup.enabled' => false]);
        $this->assertSame('ok', $backup()['status']);
    }

    public function test_a_failure_is_reported_and_remembered(): void
    {
        config(['sprint.backup.disk' => 'missing-disk']);

        $this->artisan('sprint:backup')->expectsOutputToContain('Backup failed')->assertFailed();

        $this->assertFalse(app(BackupService::class)->lastRun()['ok']);
    }
}
