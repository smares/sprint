<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MarkdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class InlineImagesTest extends TestCase
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

    private function image(string $name = 'foto.png', string $mime = 'image/png', ?Task $task = null): Attachment
    {
        return Attachment::factory()->for($task ?? $this->task)->create(['name' => $name, 'mime_type' => $mime]);
    }

    public function test_an_attachment_image_is_shown_where_it_is_placed(): void
    {
        $image = $this->image();

        $html = (string) MarkdownService::render("Davor\n\n![Skizze](attachment:{$image->id})\n\nDanach");

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('class="attachment-image"', $html);
        $this->assertStringContainsString('alt="foto.png"', $html);
        $this->assertStringContainsString(e(route('attachments.show', [$image, 'inline' => 1])), $html);
        $this->assertStringContainsString('data-download-url="'.e(route('attachments.show', $image)).'"', $html);
        $this->assertMatchesRegularExpression('/Davor.*<img.*Danach/s', $html);
    }

    public function test_images_of_projects_one_cannot_see_are_not_shown(): void
    {
        $image = $this->image();
        $this->actingAs(User::factory()->create());

        $html = (string) MarkdownService::render("![x](attachment:{$image->id})");

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('foto.png', $html);
        $this->assertStringContainsString('Bild entfernt', $html);
    }

    public function test_removed_and_non_image_attachments_show_a_placeholder(): void
    {
        $document = $this->image('bericht.pdf', 'application/pdf');
        $svg = $this->image('bild.svg', 'image/svg+xml');

        foreach (["![x](attachment:{$document->id})", "![x](attachment:{$svg->id})", '![x](attachment:99999)'] as $text) {
            $html = (string) MarkdownService::render($text);

            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringContainsString('mention-missing', $html);
        }
    }

    public function test_the_name_in_the_text_cannot_inject_html(): void
    {
        $image = $this->image('"><script>alert(1)</script>.png');

        $html = (string) MarkdownService::render("![<b>x</b>](attachment:{$image->id})");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_plain_text_shows_the_name_instead_of_the_image(): void
    {
        $this->assertSame('Siehe [Skizze] oben', MarkdownService::plainText('Siehe ![Skizze](attachment:5) oben'));
    }

    public function test_a_pasted_image_becomes_an_attachment_and_can_be_referenced(): void
    {
        $component = Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('inlineUpload', UploadedFile::fake()->image('eingefuegt.png'))
            ->call('storeInlineImage')
            ->assertHasNoErrors();

        $attachment = $this->task->attachments()->firstOrFail();
        $this->assertSame('eingefuegt.png', $attachment->name);
        $this->assertSame('image/png', $attachment->mime_type);
        Storage::disk()->assertExists($attachment->path);
        $this->assertContains('hat eingefuegt.png angehängt', $this->task->activities()->get()->map->sentence()->all());
        $component->assertSet('inlineUpload', null);
        $this->assertSame([['id' => $attachment->id, 'name' => 'eingefuegt.png']], $component->instance()->imageAttachments);
    }

    public function test_only_images_can_be_inserted_this_way(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('inlineUpload', UploadedFile::fake()->create('skript.html', 5, 'text/html'))
            ->call('storeInlineImage')
            ->assertHasErrors('inlineUpload');

        $this->assertSame(0, $this->task->attachments()->count());
    }

    public function test_viewers_cannot_insert_images(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer);

        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('inlineUpload', UploadedFile::fake()->image('x.png'))
            ->call('storeInlineImage')
            ->assertForbidden();

        $this->assertSame(0, $this->task->attachments()->count());
    }

    public function test_the_editor_offers_the_images_of_the_task(): void
    {
        $this->image('foto.png');
        $this->image('bericht.pdf', 'application/pdf');

        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->assertSee('Bild einfügen')
            ->assertSeeHtml('insertImage(');
    }

    public function test_the_task_page_renders_inserted_images_in_the_description(): void
    {
        $image = $this->image();
        $this->task->update(['description' => "![Skizze](attachment:{$image->id})"]);

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('previewMarkdown', $this->task->description)
            ->assertReturned(fn (string $html) => str_contains($html, 'class="attachment-image"'));
    }
}
