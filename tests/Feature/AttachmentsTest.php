<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        $this->task = Task::factory()->for($this->project)->create();
    }

    private function attach(string $name = 'bericht.pdf', string $content = 'PDF-Inhalt', ?Task $task = null): Attachment
    {
        $task ??= $this->task;
        $path = "attachments/{$task->project_id}/{$task->id}/".fake()->uuid();
        Storage::disk()->put($path, $content);

        return Attachment::factory()->for($task)->create(['name' => $name, 'path' => $path, 'size' => strlen($content), 'mime_type' => 'application/pdf']);
    }

    public function test_files_can_be_uploaded_on_the_task_page(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('uploads', [UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf'), UploadedFile::fake()->image('foto.png')])
            ->assertHasNoErrors()
            ->assertSet('uploads', [])
            ->assertSee('plan.pdf')
            ->assertSee('foto.png');

        $this->assertSame(2, $this->task->attachments()->count());
        $attachment = $this->task->attachments()->where('name', 'plan.pdf')->firstOrFail();
        $this->assertSame(auth()->id(), $attachment->user_id);
        $this->assertSame('application/pdf', $attachment->mime_type);
        Storage::disk()->assertExists($attachment->path);
        $this->assertContains('hat plan.pdf, foto.png angehängt', $this->task->activities()->get()->map->sentence()->all());
    }

    public function test_oversized_files_are_rejected(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('uploads', [UploadedFile::fake()->create('riesig.zip', Attachment::MAX_KILOBYTES + 1)])
            ->assertHasErrors('uploads.0');

        $this->assertSame(0, $this->task->attachments()->count());
    }

    public function test_an_attachment_can_be_removed_with_its_file(): void
    {
        $attachment = $this->attach();

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('deleteAttachment', $attachment->id);

        $this->assertModelMissing($attachment);
        Storage::disk()->assertMissing($attachment->path);
        $this->assertContains('hat den Anhang bericht.pdf entfernt', $this->task->activities()->get()->map->sentence()->all());
    }

    public function test_attachments_of_other_tasks_cannot_be_removed(): void
    {
        $foreign = $this->attach(task: Task::factory()->for($this->project)->create());

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('deleteAttachment', $foreign->id)->assertNotFound();

        $this->assertModelExists($foreign);
    }

    public function test_viewers_can_download_but_not_upload_or_delete(): void
    {
        $attachment = $this->attach();
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer);

        $this->get(route('attachments.show', $attachment))->assertOk();

        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->assertSee('bericht.pdf')
            ->assertDontSee('Dateien hinzufügen')
            ->set('uploads', [UploadedFile::fake()->create('x.pdf', 10)])->assertForbidden();

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('deleteAttachment', $attachment->id)->assertForbidden();

        $this->assertSame(1, $this->task->attachments()->count());
    }

    public function test_outsiders_cannot_download(): void
    {
        $attachment = $this->attach();
        $this->actingAs(User::factory()->create());

        $this->get(route('attachments.show', $attachment))->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $attachment = $this->attach();
        auth()->logout();

        $this->get(route('attachments.show', $attachment))->assertRedirect(route('login'));
    }

    public function test_download_delivers_the_original_name_as_a_forced_download(): void
    {
        $attachment = $this->attach('Übersicht Q3.pdf', 'Inhalt');

        $response = $this->get(route('attachments.show', $attachment))->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Q3.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('Inhalt', $response->streamedContent());
    }

    public function test_only_safe_images_are_shown_inline(): void
    {
        $image = $this->attach('foto.png', 'png');
        $image->update(['mime_type' => 'image/png']);
        $svg = $this->attach('bild.svg', '<svg onload="alert(1)"/>');
        $svg->update(['mime_type' => 'image/svg+xml']);

        $inline = $this->get(route('attachments.show', [$image, 'inline' => 1]))->assertOk();
        $this->assertStringContainsString('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame('image/png', $inline->headers->get('Content-Type'));

        $forced = $this->get(route('attachments.show', [$svg, 'inline' => 1]))->assertOk();
        $this->assertStringContainsString('attachment', $forced->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $forced->headers->get('Content-Type'));
    }

    public function test_a_missing_file_gives_404(): void
    {
        $attachment = $this->attach();
        Storage::disk()->delete($attachment->path);

        $this->get(route('attachments.show', $attachment))->assertNotFound();
    }

    public function test_deleting_a_task_removes_the_files_of_it_and_its_subtasks(): void
    {
        $child = Task::factory()->for($this->project)->create(['parent_id' => $this->task->id]);
        $grandchild = Task::factory()->for($this->project)->create(['parent_id' => $child->id]);
        $other = $this->attach('bleibt.pdf', task: Task::factory()->for($this->project)->create());
        $paths = [$this->attach()->path, $this->attach(task: $child)->path, $this->attach(task: $grandchild)->path];

        $this->task->delete();

        foreach ($paths as $path) {
            Storage::disk()->assertMissing($path);
        }
        Storage::disk()->assertExists($other->path);
        $this->assertSame(1, Attachment::count());
    }

    public function test_sizes_are_shown_in_a_readable_way(): void
    {
        $attachment = Attachment::factory()->for($this->task)->create(['size' => 1_572_864]);

        $this->assertSame('1.5 MB', $attachment->humanSize());
        $this->assertSame('10 B', Attachment::factory()->make(['size' => 10])->humanSize());
        $this->assertSame('293 KB', Attachment::factory()->make(['size' => 300000])->humanSize());
    }
}
