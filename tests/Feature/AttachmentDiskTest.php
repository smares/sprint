<?php

namespace Tests\Feature;

use App\HealthCheck;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AttachmentDiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_files_follow_the_default_disk_unless_a_disk_is_configured(): void
    {
        $this->assertSame(config('filesystems.default'), Attachment::disk());

        config(['filesystems.default' => 'bucket']);
        $this->assertSame('bucket', Attachment::disk());

        config(['sprint.attachments_disk' => 'uploads']);
        $this->assertSame('uploads', Attachment::disk());
    }

    public function test_upload_download_and_delete_use_the_configured_disk(): void
    {
        config(['sprint.attachments_disk' => 'uploads', 'filesystems.disks.uploads' => ['driver' => 'local', 'root' => storage_path('framework/testing/uploads')]]);
        Storage::fake('uploads');
        Storage::fake('local');
        $user = User::factory()->admin()->create();
        $task = Task::factory()->for(Project::factory()->create())->create();
        $this->actingAs($user);

        Livewire::test('pages::tasks.show', ['task' => $task])->set('uploads', [UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')])->assertHasNoErrors();

        $attachment = $task->attachments()->firstOrFail();
        Storage::disk('uploads')->assertExists($attachment->path);
        Storage::disk('local')->assertMissing($attachment->path);

        $this->get(route('attachments.show', $attachment))->assertOk();

        $attachment->delete();
        Storage::disk('uploads')->assertMissing($attachment->path);
    }

    public function test_the_health_check_names_the_disk_it_wrote_to(): void
    {
        config(['sprint.attachments_disk' => 'uploads']);
        Storage::fake('uploads');

        $detail = app(HealthCheck::class)->run()['storage'];

        $this->assertSame('ok', $detail['status']);
        $this->assertStringContainsString('(uploads)', $detail['detail']);
    }
}
