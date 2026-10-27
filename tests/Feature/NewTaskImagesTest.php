<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\ProjectRole;
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
}
