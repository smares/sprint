<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SavedFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->editor = User::factory()->create(['name' => 'Editorin']);
        $this->project->setRole($this->editor, ProjectRole::Editor);
        $this->actingAs($this->editor);
    }

    private function list(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->editor)->test('pages::projects.show', ['project' => $this->project]);
    }

    public function test_the_current_filters_can_be_saved_as_a_private_view(): void
    {
        $tag = Tag::factory()->for($this->project)->create();
        $priority = $this->project->customFields()->firstOrFail();
        $option = $priority->options->firstWhere('name', 'Hoch');

        $this->list()
            ->set('statusFilter', 'all')->set('assigneeFilter', 'me')->set('tagFilter', (string) $tag->id)
            ->set("fieldFilters.{$priority->id}", (string) $option->id)->call('sort', 'due')
            ->set('saveName', '  Meine dringenden  ')->call('saveCurrentFilter')->assertHasNoErrors()->assertSet('saveName', '');

        $saved = SavedFilter::firstOrFail();
        $this->assertSame('Meine dringenden', $saved->name);
        $this->assertSame($this->editor->id, $saved->user_id);
        $this->assertFalse($saved->isShared());
        $this->assertSame([
            'status' => 'all', 'assignee' => 'me', 'tag' => (string) $tag->id,
            'fields' => [(string) $priority->id => (string) $option->id], 'sort' => 'due', 'direction' => 'asc',
        ], $saved->filters);
    }

    public function test_a_saved_view_restores_filters_and_sorting(): void
    {
        $tag = Tag::factory()->for($this->project)->create();
        $priority = $this->project->customFields()->firstOrFail();
        $option = $priority->options->firstWhere('name', 'Hoch');
        SavedFilter::factory()->for($this->project)->create(['user_id' => $this->editor->id, 'filters' => [
            'status' => 'all', 'assignee' => 'me', 'tag' => (string) $tag->id,
            'fields' => [(string) $priority->id => (string) $option->id], 'sort' => 'title', 'direction' => 'desc',
        ]]);

        $this->list()->call('applyFilter', SavedFilter::first()->id)
            ->assertSet('statusFilter', 'all')->assertSet('assigneeFilter', 'me')->assertSet('tagFilter', (string) $tag->id)
            ->assertSet("fieldFilters.{$priority->id}", (string) $option->id)->assertSet('sortBy', 'title')->assertSet('sortDirection', 'desc');
    }

    public function test_applying_starts_at_the_first_page_and_shows_the_matching_tasks(): void
    {
        Task::factory()->for($this->project)->create(['title' => 'Meins', 'assignee_id' => $this->editor->id]);
        Task::factory()->for($this->project)->create(['title' => 'Fremd']);
        $saved = SavedFilter::factory()->for($this->project)->create(['user_id' => $this->editor->id, 'filters' => ['status' => 'open', 'assignee' => 'me', 'tag' => '', 'fields' => [], 'sort' => '', 'direction' => 'asc']]);

        $this->list()->assertSee('Fremd')->call('applyFilter', $saved->id)->assertSee('Meins')->assertDontSee('Fremd');
    }

    public function test_entries_that_no_longer_exist_are_skipped(): void
    {
        $saved = SavedFilter::factory()->for($this->project)->create(['user_id' => $this->editor->id, 'filters' => [
            'status' => '99999', 'assignee' => '99999', 'tag' => '99999', 'fields' => ['99999' => '1'], 'sort' => 'field:99999', 'direction' => 'sideways',
        ]]);

        $this->list()->call('applyFilter', $saved->id)
            ->assertSet('statusFilter', 'open')->assertSet('assigneeFilter', '')->assertSet('tagFilter', '')
            ->assertSet('fieldFilters', [])->assertSet('sortBy', '')->assertSet('sortDirection', 'asc');
    }

    public function test_foreign_ids_in_a_saved_view_are_not_applied(): void
    {
        $otherStatus = Project::factory()->create()->statuses()->first();
        $otherTag = Tag::factory()->for(Project::factory()->create())->create();
        $saved = SavedFilter::factory()->for($this->project)->create(['user_id' => $this->editor->id, 'filters' => [
            'status' => (string) $otherStatus->id, 'assignee' => '', 'tag' => (string) $otherTag->id, 'fields' => [], 'sort' => '', 'direction' => 'asc',
        ]]);

        $this->list()->call('applyFilter', $saved->id)->assertSet('statusFilter', 'open')->assertSet('tagFilter', '');
    }

    public function test_views_are_private_unless_a_manager_shares_them(): void
    {
        $mine = SavedFilter::factory()->for($this->project)->create(['user_id' => $this->editor->id, 'name' => 'Privat']);
        $theirs = SavedFilter::factory()->for($this->project)->create(['user_id' => User::factory()->create()->id, 'name' => 'Fremd privat']);
        $shared = SavedFilter::factory()->for($this->project)->shared()->create(['name' => 'Für alle']);

        $page = $this->list();
        $this->assertEqualsCanonicalizing(['Privat', 'Für alle'], $page->instance()->savedFilters->pluck('name')->all());

        $page->call('applyFilter', $theirs->id)->assertNotFound();
        $this->list()->call('deleteFilter', $theirs->id)->assertNotFound();
        $this->assertNotNull($mine);
        $this->assertNotNull($shared);
    }

    public function test_only_managers_can_share_a_view(): void
    {
        $this->list()->set('saveName', 'Teilen?')->set('saveShared', true)->call('saveCurrentFilter');
        $this->assertFalse(SavedFilter::firstOrFail()->isShared());

        $manager = User::factory()->create();
        $this->project->setRole($manager, ProjectRole::Admin);
        $this->list($manager)->set('saveName', 'Für alle')->set('saveShared', true)->call('saveCurrentFilter');

        $this->assertTrue(SavedFilter::where('name', 'Für alle')->firstOrFail()->isShared());
        $this->assertSame(['Für alle'], $this->list()->instance()->savedFilters->pluck('name')->diff(['Teilen?'])->values()->all());
    }

    public function test_saving_under_the_same_name_updates_the_view(): void
    {
        $this->list()->set('statusFilter', 'all')->set('saveName', 'Meine')->call('saveCurrentFilter');
        $this->list()->set('assigneeFilter', 'me')->set('saveName', 'Meine')->call('saveCurrentFilter');

        $this->assertSame(1, SavedFilter::count());
        $this->assertSame('me', SavedFilter::first()->filters['assignee']);
    }

    public function test_the_name_is_required_and_limited(): void
    {
        $this->list()->call('saveCurrentFilter')->assertHasErrors('saveName');
        $this->list()->set('saveName', str_repeat('x', 81))->call('saveCurrentFilter')->assertHasErrors('saveName');

        $this->assertSame(0, SavedFilter::count());
    }

    public function test_own_views_can_be_deleted_and_shared_ones_only_by_managers(): void
    {
        $mine = SavedFilter::factory()->for($this->project)->create(['user_id' => $this->editor->id]);
        $shared = SavedFilter::factory()->for($this->project)->shared()->create();

        $this->list()->call('deleteFilter', $mine->id);
        $this->assertModelMissing($mine);

        $this->list()->call('deleteFilter', $shared->id)->assertForbidden();
        $this->assertModelExists($shared);

        $manager = User::factory()->create();
        $this->project->setRole($manager, ProjectRole::Admin);
        $this->list($manager)->call('deleteFilter', $shared->id);
        $this->assertModelMissing($shared);
    }

    public function test_views_of_other_projects_are_unreachable(): void
    {
        $foreign = SavedFilter::factory()->for(Project::factory()->create())->shared()->create();

        $this->list()->call('applyFilter', $foreign->id)->assertNotFound();
        $this->list()->call('deleteFilter', $foreign->id)->assertNotFound();
    }

    public function test_viewers_can_use_and_save_views_too(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        $this->list($viewer)->set('statusFilter', 'all')->set('saveName', 'Alles')->call('saveCurrentFilter')->assertHasNoErrors();

        $this->assertSame($viewer->id, SavedFilter::firstOrFail()->user_id);
    }

    public function test_deleting_the_project_or_the_person_removes_their_views(): void
    {
        SavedFilter::factory()->for($this->project)->create(['user_id' => $this->editor->id]);
        SavedFilter::factory()->for($this->project)->shared()->create();

        $this->editor->delete();
        $this->assertSame(1, SavedFilter::count());

        $this->project->delete();
        $this->assertSame(0, SavedFilter::count());
    }

    public function test_the_views_button_and_window_are_on_the_list(): void
    {
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('Ansichten')->assertSee('data-modal="saved-filters"', false)->assertSee('Noch keine Ansichten gespeichert.');
    }
}
