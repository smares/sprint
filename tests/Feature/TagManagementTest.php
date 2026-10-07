<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagManagementTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
    }

    private function page()
    {
        return Livewire::test('pages::projects.tags', ['project' => $this->project]);
    }

    public function test_only_managers_can_open_the_page(): void
    {
        $editor = User::factory()->create();
        $manager = User::factory()->create();
        $this->project->setRole($editor, ProjectRole::Editor);
        $this->project->setRole($manager, ProjectRole::Admin);
        Tag::factory()->for($this->project)->create(['name' => 'Dringend']);

        $this->actingAs($editor)->get(route('projects.tags', $this->project))->assertForbidden();
        $this->actingAs($manager)->get(route('projects.tags', $this->project))->assertOk()->assertSee('Dringend');
    }

    public function test_the_list_page_links_to_it_for_managers_only(): void
    {
        $this->get(route('projects.show', $this->project))->assertSee(route('projects.tags', $this->project), false);

        $editor = User::factory()->create();
        $this->project->setRole($editor, ProjectRole::Editor);
        $this->actingAs($editor)->get(route('projects.show', $this->project))->assertDontSee(route('projects.tags', $this->project), false);
    }

    public function test_tags_show_how_often_they_are_used(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        Task::factory()->for($this->project)->count(2)->create()->each(fn (Task $task) => $task->tags()->attach($tag));

        $this->assertSame(2, $this->page()->instance()->tags->first()->tasks_count);
    }

    public function test_a_tag_can_be_created_with_a_free_color(): void
    {
        $this->page()->set('newName', '  Neu  ')->call('add')->assertHasNoErrors()->assertSet('newName', '');

        $tag = $this->project->tags()->firstOrFail();
        $this->assertSame('Neu', $tag->name);
        $this->assertContains($tag->color, Tag::COLORS);
    }

    public function test_names_must_be_unique_within_the_project_ignoring_case_but_not_across_projects(): void
    {
        Tag::factory()->for($this->project)->create(['name' => 'Dringend']);
        $other = Project::factory()->create();
        Tag::factory()->for($other)->create(['name' => 'Anders']);

        $this->page()->set('newName', 'DRINGEND')->call('add')->assertHasErrors('newName');
        $this->page()->set('newName', 'Anders')->call('add')->assertHasNoErrors();

        $this->assertSame(2, $this->project->tags()->count());
        $this->assertSame(1, $other->tags()->count());
    }

    public function test_renaming_works_and_keeps_the_assignments(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Alt']);
        $task = Task::factory()->for($this->project)->create();
        $task->tags()->attach($tag);

        $this->page()->set("names.{$tag->id}", ' Neu ');

        $this->assertSame('Neu', $tag->fresh()->name);
        $this->assertSame([$tag->id], $task->tags()->pluck('tags.id')->all());
    }

    public function test_invalid_or_duplicate_names_are_reverted(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Eins']);
        Tag::factory()->for($this->project)->create(['name' => 'Zwei']);

        $this->page()->set("names.{$tag->id}", 'zwei')->assertSet("names.{$tag->id}", 'Eins');
        $this->page()->set("names.{$tag->id}", '   ')->assertSet("names.{$tag->id}", 'Eins');
        $this->page()->set("names.{$tag->id}", str_repeat('x', 51))->assertSet("names.{$tag->id}", 'Eins');

        $this->assertSame('Eins', $tag->fresh()->name);
    }

    public function test_the_color_can_be_changed_to_known_colors_only(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['color' => 'red']);

        $this->page()->set("colors.{$tag->id}", 'purple');
        $this->assertSame('purple', $tag->fresh()->color);

        $this->page()->set("colors.{$tag->id}", 'neon')->assertSet("colors.{$tag->id}", 'purple');
        $this->assertSame('purple', $tag->fresh()->color);
    }

    public function test_deleting_removes_the_tag_from_all_tasks(): void
    {
        $tag = Tag::factory()->for($this->project)->create();
        $task = Task::factory()->for($this->project)->create();
        $task->tags()->attach($tag);

        $this->page()->call('confirmDelete', $tag->id)->call('delete')->assertHasNoErrors();

        $this->assertModelMissing($tag);
        $this->assertSame(0, $task->tags()->count());
        $this->assertModelExists($task);
    }

    public function test_deleting_can_move_the_tasks_to_another_tag_without_duplicates(): void
    {
        $old = Tag::factory()->for($this->project)->create(['name' => 'Alt']);
        $new = Tag::factory()->for($this->project)->create(['name' => 'Neu']);
        $onlyOld = Task::factory()->for($this->project)->create();
        $both = Task::factory()->for($this->project)->create();
        $onlyOld->tags()->attach($old);
        $both->tags()->attach([$old->id, $new->id]);

        $this->page()->call('confirmDelete', $old->id)->set('mergeIntoId', (string) $new->id)->call('delete')->assertHasNoErrors();

        $this->assertModelMissing($old);
        $this->assertSame([$new->id], $onlyOld->tags()->pluck('tags.id')->all());
        $this->assertSame([$new->id], $both->tags()->pluck('tags.id')->all());
    }

    public function test_the_replacement_must_belong_to_the_project_and_differ_from_the_deleted_tag(): void
    {
        $tag = Tag::factory()->for($this->project)->create();
        $foreign = Tag::factory()->for(Project::factory()->create())->create();

        $this->page()->call('confirmDelete', $tag->id)->set('mergeIntoId', (string) $foreign->id)->call('delete')->assertHasErrors('mergeIntoId');
        $this->page()->call('confirmDelete', $tag->id)->set('mergeIntoId', (string) $tag->id)->call('delete')->assertHasErrors('mergeIntoId');

        $this->assertModelExists($tag);
    }

    public function test_tags_of_other_projects_cannot_be_touched(): void
    {
        $foreign = Tag::factory()->for(Project::factory()->create())->create(['name' => 'Fremd']);

        $this->page()->call('confirmDelete', $foreign->id)->assertNotFound();
        $this->page()->set("names.{$foreign->id}", 'Gekapert')->assertNotFound();

        $this->assertSame('Fremd', $foreign->fresh()->name);
    }
}
