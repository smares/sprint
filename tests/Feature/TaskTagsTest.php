<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_tag_can_be_created_from_the_task_page_and_is_selected(): void
    {
        $task = Task::factory()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('newTag', 'Bug')
            ->call('createTag')
            ->assertHasNoErrors()
            ->assertSet('newTag', '');

        $tag = Tag::where('name', 'Bug')->firstOrFail();
        $this->assertSame($task->project_id, $tag->project_id);
    }

    public function test_creating_an_existing_tag_name_reuses_the_tag(): void
    {
        $task = Task::factory()->create();
        Tag::factory()->for($task->project)->create(['name' => 'Bug']);

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('newTag', 'Bug')
            ->call('createTag');

        $this->assertSame(1, Tag::where('name', 'Bug')->count());
    }

    public function test_tags_are_saved_with_the_task(): void
    {
        $task = Task::factory()->create();
        $tags = Tag::factory()->count(2)->for($task->project)->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('tagIds', $tags->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing($tags->pluck('id')->all(), $task->tags()->pluck('tags.id')->all());
    }

    public function test_tags_of_another_project_are_rejected(): void
    {
        $task = Task::factory()->create();
        $foreign = Tag::factory()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('tagIds', [(string) $foreign->id])
            ->call('save')
            ->assertHasErrors('tagIds.0');

        $this->assertCount(0, $task->tags);
    }

    public function test_list_can_be_filtered_by_tag(): void
    {
        $project = Project::factory()->create();
        $tag = Tag::factory()->for($project)->create(['name' => 'Wichtig']);
        Task::factory()->for($project)->create(['title' => 'Mit Tag'])->tags()->attach($tag);
        Task::factory()->for($project)->create(['title' => 'Ohne Tag']);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->set('tagFilter', (string) $tag->id)
            ->assertSee('Mit Tag')
            ->assertDontSee('Ohne Tag');
    }

    public function test_board_shows_tags_on_cards(): void
    {
        $project = Project::factory()->create();
        $tag = Tag::factory()->for($project)->create(['name' => 'Backend']);
        Task::factory()->for($project)->create()->tags()->attach($tag);

        $this->get(route('projects.board', $project))->assertOk()->assertSee('Backend');
    }
}
