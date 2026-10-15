<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DescriptionPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->task = Task::factory()->for(Project::factory()->create())->create();
    }

    public function test_a_task_with_a_description_opens_on_the_rendered_text(): void
    {
        $this->task->update(['description' => 'Ein **fetter** Text']);

        $component = Livewire::test('pages::tasks.show', ['task' => $this->task]);

        $this->assertStringContainsString('<strong>fetter</strong>', (string) $component->instance()->descriptionPreview);
        $component->assertSeeHtml("tab: 'preview'");
    }

    public function test_the_toolbar_lists_preview_before_edit_in_the_order_of_the_screen_and_is_hidden_until_alpine_runs(): void
    {
        $this->task->update(['description' => 'Text']);

        $html = Livewire::test('pages::tasks.show', ['task' => $this->task])->html();

        // Preview comes first (it is what is shown), then Edit; the field stays out of sight until Alpine has decided
        $this->assertLessThan(strpos($html, '>Bearbeiten<'), strpos($html, '>Vorschau<'));
        $this->assertStringContainsString("aria-pressed=\"tab === 'preview'\"", $html);
        $this->assertMatchesRegularExpression('/x-show="tab === \'write\'"\s+x-cloak/', $html);
    }

    public function test_a_task_without_a_description_opens_the_field(): void
    {
        $this->task->update(['description' => null]);

        $component = Livewire::test('pages::tasks.show', ['task' => $this->task]);

        $this->assertNull($component->instance()->descriptionPreview);
        $component->assertDontSeeHtml("tab: 'preview'");
    }

    public function test_saving_sends_the_page_back_to_the_preview(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])->set('description', 'Neuer Text')->call('save')->assertDispatched('task-saved');
    }
}
