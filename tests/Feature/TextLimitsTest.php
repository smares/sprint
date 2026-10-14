<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TextLimitsTest extends TestCase
{
    use RefreshDatabase;

    /** A family: three people and two joiners, which is five characters (code points) but eighteen bytes. */
    private const string FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->task = Task::factory()->for(Project::factory()->create())->create();
    }

    private function saveDescription(string $text): mixed
    {
        return Livewire::test('pages::tasks.show', ['task' => $this->task])->set('description', $text)->call('save');
    }

    public function test_a_long_description_from_another_tool_can_be_saved(): void
    {
        $text = str_repeat('x', Task::MAX_DESCRIPTION_LENGTH);

        $this->saveDescription($text)->assertHasNoErrors();

        $this->assertSame(Task::MAX_DESCRIPTION_LENGTH, mb_strlen((string) $this->task->fresh()->description));
        $this->saveDescription($text.'x')->assertHasErrors('description');
    }

    public function test_characters_are_counted_as_unicode_characters_not_bytes(): void
    {
        $fits = str_repeat(self::FAMILY, intdiv(Task::MAX_DESCRIPTION_LENGTH, 5));

        $this->assertSame(Task::MAX_DESCRIPTION_LENGTH, mb_strlen($fits));
        $this->assertGreaterThan(Task::MAX_DESCRIPTION_LENGTH * 3, strlen($fits));
        $this->saveDescription($fits)->assertHasNoErrors();

        // A letter with a separate accent counts for both parts, as the counter on the page does
        $this->saveDescription($fits."e\u{0301}")->assertHasErrors('description');
    }

    public function test_the_preview_renders_a_long_text_in_full(): void
    {
        $text = str_repeat("Zeile\n\n", 5000).'**Das Ende**';

        $html = Livewire::test('pages::tasks.show', ['task' => $this->task])->instance()->previewMarkdown($text);

        $this->assertStringContainsString('<strong>Das Ende</strong>', $html);
    }

    public function test_a_long_comment_can_be_added(): void
    {
        $this->assertGreaterThan(5000, Comment::MAX_BODY_LENGTH);

        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('comment', str_repeat('y', Comment::MAX_BODY_LENGTH))
            ->call('addComment')
            ->assertHasNoErrors();

        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('comment', str_repeat('y', Comment::MAX_BODY_LENGTH + 1))
            ->call('addComment')
            ->assertHasErrors('comment');
    }

    public function test_the_page_shows_a_counter_with_the_limits(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->assertSeeHtml('max: '.Task::MAX_DESCRIPTION_LENGTH)
            ->assertSeeHtml('max: '.Comment::MAX_BODY_LENGTH);
    }
}
