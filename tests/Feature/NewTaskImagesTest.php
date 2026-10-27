<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\ProjectRole;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\TestCase;

class NewTaskImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->anna = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->anna, ProjectRole::Editor);
    }

    public function test_the_dialog_offers_pasting_and_dropping_images(): void
    {
        Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->assertSeeHtml('x-on:paste="onPaste($event)"')
            ->assertSeeHtml('x-on:drop="onDrop($event)"');
    }

    public function test_an_image_in_the_description_becomes_an_attachment_of_the_new_task(): void
    {
        $dialog = Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->set('inlineUpload', UploadedFile::fake()->image('screenshot.png'))
            ->call('storeInlineImage')
            ->assertReturned(fn (array $image) => $image['name'] === 'screenshot.png' && $image['id'] > 9_000_000_000);

        $draftId = array_key_first($dialog->get('draftImages'));

        $dialog->set('title', 'Mit Bild')->set('description', "Siehe ![screenshot.png](attachment:{$draftId})")->call('create')->assertHasNoErrors();

        $task = Task::where('title', 'Mit Bild')->firstOrFail();
        $attachment = $task->attachments()->firstOrFail();

        $this->assertSame("Siehe ![screenshot.png](attachment:{$attachment->id})", $task->description);
        $this->assertSame('screenshot.png', $attachment->name);
        Storage::assertExists($attachment->path);
        $this->assertTrue($task->activities()->where('type', ActivityType::AttachmentsAdded)->exists());
        $this->assertSame([], $dialog->get('draftImages'));
    }

    public function test_an_image_the_text_no_longer_mentions_is_not_attached(): void
    {
        $dialog = Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->set('inlineUpload', UploadedFile::fake()->image('weg.png'))
            ->call('storeInlineImage');

        $dialog->set('title', 'Ohne Bild')->set('description', 'Das Bild wurde wieder gelöscht')->call('create');

        $this->assertSame(0, Task::where('title', 'Ohne Bild')->firstOrFail()->attachments()->count());
    }

    public function test_only_images_are_accepted(): void
    {
        Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->set('inlineUpload', UploadedFile::fake()->create('virus.exe', 10))
            ->call('storeInlineImage')
            ->assertHasErrors('inlineUpload');
    }

    public function test_viewers_cannot_upload(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        Livewire::actingAs($viewer)->test('task-create', ['project' => $this->project])->assertForbidden();
    }

    public function test_textlinks_are_never_underlined_all_the_time(): void
    {
        $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));

        foreach ($directory as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), '/mail/')) {
                continue;
            }

            preg_match_all('/<flux:link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', (string) file_get_contents($file->getPathname()), $links);

            foreach ($links[0] as $link) {
                $this->assertSame(1, substr_count($link, 'variant='), $file->getFilename().': a text link needs exactly one variant: '.$link);
                $this->assertMatchesRegularExpression('/(?<![:\w-])variant="(ghost|subtle)"/', $link, $file->getFilename().': ghost (underlined on hover) or subtle (never) only: '.$link);
            }
        }
    }

    public function test_files_chosen_in_the_dialog_become_attachments_of_the_new_task(): void
    {
        $dialog = Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->set('uploads', [UploadedFile::fake()->create('angebot.pdf', 50, 'application/pdf'), UploadedFile::fake()->create('notizen.txt', 2, 'text/plain')])
            ->assertHasNoErrors()
            ->assertSee('angebot.pdf')
            ->set('title', 'Mit Dateien')
            ->call('create')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Mit Dateien')->firstOrFail();

        $this->assertEqualsCanonicalizing(['angebot.pdf', 'notizen.txt'], $task->attachments()->pluck('name')->all());
        $this->assertSame([], $dialog->get('uploads'));
        $this->assertSame(1, $task->activities()->where('type', ActivityType::AttachmentsAdded)->count());
    }

    public function test_a_chosen_file_can_be_taken_out_again(): void
    {
        $dialog = Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->set('uploads', [UploadedFile::fake()->create('a.txt', 1), UploadedFile::fake()->create('b.txt', 1)])
            ->call('removeUpload', 0);

        $this->assertCount(1, $dialog->get('uploads'));

        $dialog->set('title', 'Eine Datei')->call('create');

        $this->assertSame(['b.txt'], Task::where('title', 'Eine Datei')->firstOrFail()->attachments()->pluck('name')->all());
    }

    public function test_files_follow_the_limits_of_the_attachments_of_a_task(): void
    {
        $tooMany = array_map(fn (int $n) => UploadedFile::fake()->create("datei-{$n}.txt", 1), range(1, 21));

        Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->set('uploads', $tooMany)->assertHasErrors('uploads');

        Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->set('uploads', [UploadedFile::fake()->create('riesig.zip', Attachment::MAX_KILOBYTES + 1)])->assertHasErrors('uploads.0');
    }

    public function test_the_preview_announces_an_image_that_waits_for_the_task(): void
    {
        $html = Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->call('previewMarkdown', 'Siehe ![bild.png](attachment:9000000001)')
            ->effects['returns'][0] ?? '';

        $this->assertStringContainsString('wird angehängt', $html);
        $this->assertStringNotContainsString('Bild entfernt', $html);
    }

    public function test_a_failure_while_attaching_leaves_no_task_and_no_file_behind(): void
    {
        $dialog = Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project])
            ->set('uploads', [UploadedFile::fake()->create('a.txt', 2), UploadedFile::fake()->create('b.txt', 2)])
            ->set('title', 'Soll nicht entstehen');

        $created = 0;
        Attachment::creating(function () use (&$created): void {
            throw_if(++$created === 2, RuntimeException::class, 'Datenträger voll');
        });

        try {
            $dialog->call('create');
            $this->fail('The failure should reach the person.');
        } catch (RuntimeException) {
            // expected: the dialog stays as it was, nothing exists twice
        }

        $this->assertSame(0, Task::where('title', 'Soll nicht entstehen')->count());
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], array_values(array_filter(Storage::allFiles(), fn (string $path) => str_starts_with($path, 'attachments/'))));
    }

    public function test_the_21st_image_in_a_text_is_refused_with_a_message(): void
    {
        $dialog = Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project]);

        foreach (range(1, 20) as $n) {
            $dialog->set('inlineUpload', UploadedFile::fake()->image("bild-{$n}.png"))->call('storeInlineImage')->assertHasNoErrors();
        }

        $dialog->set('inlineUpload', UploadedFile::fake()->image('zu-viel.png'))->call('storeInlineImage')->assertHasErrors('inlineUpload');
    }
}
