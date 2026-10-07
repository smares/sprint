<?php

namespace Tests\Feature;

use App\Markdown;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MarkdownMentionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    public function test_markdown_is_rendered(): void
    {
        $html = (string) Markdown::render("# Titel\n\n**fett** und _kursiv_\n\n- eins\n- zwei\n\n`code`");

        $this->assertStringContainsString('<h1>Titel</h1>', $html);
        $this->assertStringContainsString('<strong>fett</strong>', $html);
        $this->assertStringContainsString('<em>kursiv</em>', $html);
        $this->assertStringContainsString('<li>eins</li>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function test_github_flavoured_features_work(): void
    {
        $html = (string) Markdown::render("~~weg~~\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n- [x] erledigt\n\nhttps://example.com/seite");

        $this->assertStringContainsString('<del>weg</del>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('href="https://example.com/seite"', $html);
    }

    public function test_empty_text_renders_nothing(): void
    {
        $this->assertSame('', (string) Markdown::render(null));
        $this->assertSame('', (string) Markdown::render("  \n "));
    }

    public function test_raw_html_and_scripts_are_stripped(): void
    {
        $html = (string) Markdown::render("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\nText <b onclick=\"x()\">fett</b>");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_unsafe_links_are_blocked(): void
    {
        $html = (string) Markdown::render('[klick](javascript:alert(1)) [auch](data:text/html;base64,AAAA) [ok](https://example.com)');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function test_external_links_open_safely_in_a_new_window(): void
    {
        $html = (string) Markdown::render('[extern](https://example.org/x)');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('noopener', $html);
        $this->assertStringContainsString('noreferrer', $html);
    }

    public function test_user_mention_shows_the_current_name(): void
    {
        $person = User::factory()->admin()->create(['name' => 'Anna Beispiel']);

        $html = (string) Markdown::render("Hallo @[Alter Name]({$this->mention('user', $person->id)}) bitte prüfen");

        $this->assertStringContainsString('<span class="mention mention-user">@Anna Beispiel</span>', $html);
        $this->assertStringNotContainsString('Alter Name', $html);
    }

    public function test_task_mention_links_to_the_task_with_its_current_title(): void
    {
        $task = Task::factory()->create(['title' => 'Aktueller Titel']);

        $html = (string) Markdown::render("Siehe @[Alter Titel]({$this->mention('task', $task->id)})");

        $this->assertStringContainsString('href="'.route('tasks.show', $task).'"', $html);
        $this->assertStringContainsString('>Aktueller Titel</a>', $html);
        $this->assertStringNotContainsString('Alter Titel', $html);
    }

    public function test_mentions_of_deleted_records_are_marked_as_missing(): void
    {
        $html = (string) Markdown::render('@[Gelöscht](user:99999) und @[Weg](task:99999)');

        $this->assertSame(2, substr_count($html, 'mention-missing'));
        $this->assertStringContainsString('@Gelöscht', $html);
        $this->assertStringContainsString('Weg', $html);
    }

    public function test_mention_names_are_escaped(): void
    {
        $person = User::factory()->admin()->create(['name' => '<b>Evil</b> "Name"']);
        $task = Task::factory()->create(['title' => '<script>x</script>']);

        $html = (string) Markdown::render("@[x](user:{$person->id}) @[y](task:{$task->id})");

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt;', $html);
    }

    public function test_several_mentions_and_formatting_combine(): void
    {
        $first = User::factory()->admin()->create(['name' => 'Anna']);
        $second = User::factory()->admin()->create(['name' => 'Ben']);

        $html = (string) Markdown::render("**@[a](user:{$first->id})** und *@[b](user:{$second->id})*");

        $this->assertStringContainsString('<strong><span class="mention mention-user">@Anna</span></strong>', $html);
        $this->assertStringContainsString('<em><span class="mention mention-user">@Ben</span></em>', $html);
    }

    public function test_preview_action_renders_markdown(): void
    {
        $task = Task::factory()->create();

        $preview = Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('previewMarkdown', '**hi** <script>x</script>')
            ->effects['returns'][0] ?? null;

        $this->assertStringContainsString('<strong>hi</strong>', (string) $preview);
        $this->assertStringNotContainsString('<script', (string) $preview);
    }

    public function test_description_is_saved_as_raw_markdown(): void
    {
        $task = Task::factory()->create();
        $person = User::factory()->admin()->create();
        $text = "**wichtig** für @[x](user:{$person->id})";

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('description', $text)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($text, $task->fresh()->description);
    }

    public function test_comments_are_rendered_as_markdown_with_mentions(): void
    {
        $task = Task::factory()->create();
        $person = User::factory()->admin()->create(['name' => 'Clara Test']);
        Comment::factory()->for($task)->create([
            'user_id' => $this->user->id,
            'body' => "Das ist **wichtig**, @[x](user:{$person->id})!",
        ]);

        $this->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('<strong>wichtig</strong>', false)
            ->assertSee('@Clara Test');
    }

    public function test_task_page_offers_people_and_tasks_of_the_project_for_mentions(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['title' => 'Hauptaufgabe']);
        Task::factory()->for($project)->create(['title' => 'Nachbaraufgabe']);
        Task::factory()->for($project)->create(['title' => 'Überschrift', 'is_section' => true]);
        Task::factory()->create(['title' => 'Fremdes Projekt']);
        User::factory()->admin()->create(['name' => 'Ben Muster']);

        $options = Livewire::test('pages::tasks.show', ['task' => $task])->instance()->mentionOptions;

        $this->assertContains('Ben Muster', array_column($options['users'], 'name'));
        $this->assertEqualsCanonicalizing(['Hauptaufgabe', 'Nachbaraufgabe'], array_column($options['tasks'], 'title'));

        $this->get(route('tasks.show', $task))->assertOk()->assertSee('mentionable', false);
    }

    private function mention(string $type, int $id): string
    {
        return "$type:$id";
    }
}
