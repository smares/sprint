<?php

namespace Tests\Feature;

use App\Color;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        return Livewire::test('project-tags', ['project' => $this->project]);
    }

    public function test_only_managers_get_the_tag_modal(): void
    {
        $editor = User::factory()->create();
        $manager = User::factory()->create();
        $this->project->setRole($editor, ProjectRole::Editor);
        $this->project->setRole($manager, ProjectRole::Admin);
        Tag::factory()->for($this->project)->create(['name' => 'Dringend']);

        $this->actingAs($editor)->get(route('projects.show', $this->project))->assertOk()->assertDontSee('project-tags', false)->assertDontSee('Neuer Tag');
        Livewire::test('project-tags', ['project' => $this->project])->assertForbidden();

        $this->actingAs($manager)->get(route('projects.show', $this->project))->assertOk()->assertSee('project-tags', false)->assertSee('Dringend');
    }

    public function test_actions_need_the_manage_right_even_after_loading(): void
    {
        $manager = User::factory()->create();
        $this->project->setRole($manager, ProjectRole::Admin);
        $component = Livewire::actingAs($manager)->test('project-tags', ['project' => $this->project]);

        $this->project->setRole($manager, ProjectRole::Viewer);

        $component->set('newName', 'Neu')->assertForbidden();
        $this->assertSame(0, $this->project->tags()->count());
    }

    public function test_changes_are_announced_to_the_page_once_the_modal_closes(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Alt']);

        foreach ([
            fn ($page) => $page->set('newName', 'Neu')->call('add'),
            fn ($page) => $page->set("names.{$tag->id}", 'Umbenannt'),
            fn ($page) => $page->set("colors.{$tag->id}", '#0ea5e9'),
            fn ($page) => $page->call('confirmDelete', $tag->id)->call('delete'),
        ] as $change) {
            $page = $this->page();
            $change($page)->assertNotDispatched('tags-changed')->call('closed')->assertDispatched('tags-changed');
            $page->call('closed')->assertSet('dirty', false);
        }

        $this->page()->call('closed')->assertNotDispatched('tags-changed');
    }

    public function test_deleting_shows_a_confirmation_inline_and_can_be_cancelled(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Alt']);

        $this->page()
            ->assertDontSee('Das Tag wird von allen Aufgaben entfernt')
            ->call('confirmDelete', $tag->id)
            ->assertSet('deletingId', (string) $tag->id)
            ->assertSee('Das Tag wird von allen Aufgaben entfernt')
            ->call('cancelDelete')
            ->assertSet('deletingId', '')
            ->assertDontSee('Das Tag wird von allen Aufgaben entfernt');

        $this->assertModelExists($tag);
    }

    public function test_the_list_drops_a_filter_on_a_deleted_tag(): void
    {
        $tag = Tag::factory()->for($this->project)->create();

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->set('tagFilter', (string) $tag->id)
            ->tap(fn () => $tag->delete())
            ->dispatch('tags-changed')
            ->assertSet('tagFilter', '');
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
        $this->assertContains($tag->color, Color::hexes());
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

    public function test_the_color_can_be_picked_freely_as_hex(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['color' => '#ef4444']);

        $this->page()->set("colors.{$tag->id}", '#A1B2C3');

        $this->assertSame('#a1b2c3', $tag->fresh()->color);
    }

    public function test_invalid_colors_are_rejected_and_the_old_one_comes_back(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['color' => '#a855f7']);

        foreach (['neon', 'purple', '#fff', '#12345g', 'red; background: url(x)', ''] as $invalid) {
            $this->page()->set("colors.{$tag->id}", $invalid)->assertSet("colors.{$tag->id}", '#a855f7');
        }

        $this->assertSame('#a855f7', $tag->fresh()->color);
    }

    public function test_old_color_names_are_still_understood(): void
    {
        $tag = Tag::factory()->for($this->project)->create();
        DB::table('tags')->where('id', $tag->id)->update(['color' => 'purple']);

        $this->assertSame('#a855f7', $tag->fresh()->color);
        $this->page()->assertSet("colors.{$tag->id}", '#a855f7');
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
