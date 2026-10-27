<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MarkdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskUrlLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->anna, ProjectRole::Editor);
        $this->task = Task::factory()->for($this->project)->create(['title' => 'Angebot schreiben']);
        $this->actingAs($this->anna);
    }

    public function test_a_pasted_task_address_is_shown_with_the_title_of_the_task(): void
    {
        $url = route('tasks.show', $this->task);

        foreach ([$url, "<$url>", "[$url]($url)", "Siehe $url bitte"] as $text) {
            $html = (string) MarkdownService::render($text);

            $this->assertStringContainsString('<a class="mention mention-task" href="'.e($url).'">Angebot schreiben</a>', $html, $text);
        }
    }

    public function test_the_address_of_a_list_with_the_task_open_counts_too(): void
    {
        $url = route('projects.show', ['project' => $this->project, 'sort' => 'due', 'task' => $this->task->id]);

        $this->assertStringContainsString('mention-task', (string) MarkdownService::render($url));
        $this->assertSame($this->task->id, MarkdownService::taskIdFromUrl($url));
    }

    public function test_the_title_follows_the_task_and_is_escaped(): void
    {
        $this->task->update(['title' => '<b>Neu</b> & anders']);

        $html = (string) MarkdownService::render(route('tasks.show', $this->task));

        $this->assertStringContainsString('&lt;b&gt;Neu&lt;/b&gt; &amp; anders</a>', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_other_addresses_and_links_with_words_of_their_own_stay_as_they_are(): void
    {
        $url = route('tasks.show', $this->task);

        $this->assertStringNotContainsString('mention-task', (string) MarkdownService::render("[das Angebot]($url)"));
        $this->assertStringNotContainsString('mention-task', (string) MarkdownService::render('https://example.com/tasks/'.$this->task->id));
        $this->assertStringNotContainsString('mention-task', (string) MarkdownService::render('https://example.com/?task='.$this->task->id));
        $this->assertStringNotContainsString('mention-task', (string) MarkdownService::render("`$url`"));
        $this->assertNull(MarkdownService::taskIdFromUrl(url('/tasks/abc')));
        $this->assertNull(MarkdownService::taskIdFromUrl(url('/tasks/1/edit')));
        $this->assertNull(MarkdownService::taskIdFromUrl('javascript:alert(1)'));
    }

    public function test_a_task_that_is_gone_or_not_visible_stays_a_plain_link(): void
    {
        $url = route('tasks.show', $this->task);
        $gone = route('tasks.show', $this->task->id + 1000);

        $html = (string) MarkdownService::render("$url $gone");
        $this->assertSame(1, substr_count($html, 'mention-task'));
        $this->assertStringContainsString('<a href="'.e($gone).'">', $html);

        $this->actingAs(User::factory()->create());
        $html = (string) MarkdownService::render($url);
        $this->assertStringNotContainsString('Angebot schreiben', $html);
        $this->assertStringContainsString('<a href="'.e($url).'">', $html);
    }

    public function test_the_editor_asks_for_the_title_of_a_pasted_address(): void
    {
        $editor = Livewire::actingAs($this->anna)->test('task-create', ['project' => $this->project]);
        $other = Project::factory()->create();
        $hidden = Task::factory()->for($other)->create();

        $this->assertSame(['id' => $this->task->id, 'title' => 'Angebot schreiben'], $editor->instance()->resolveTaskLink(route('tasks.show', $this->task)));
        $this->assertNull($editor->instance()->resolveTaskLink(route('tasks.show', $hidden)));
        $this->assertNull($editor->instance()->resolveTaskLink('https://example.com/tasks/'.$this->task->id));

        $this->task->delete();
        $this->assertNull($editor->instance()->resolveTaskLink(route('tasks.show', $this->task->id)));
    }

    public function test_every_editor_listens_for_pasted_addresses(): void
    {
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('x-on:paste="onPaste($event)"', false)->assertSee('resolveTaskLink', false);
    }
}
