<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\ProjectRole;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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

    public function test_textlinks_are_not_underlined_until_hovered(): void
    {
        foreach (glob(resource_path('views').'/{components,pages,pages/*}/*.blade.php', GLOB_BRACE) as $file) {
            preg_match_all('/<flux:link\b[^>]*>/', (string) file_get_contents($file), $links);

            foreach ($links[0] as $link) {
                $this->assertStringContainsString('variant="ghost"', $link, basename($file).' has a text link that is always underlined: '.$link);
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
}
