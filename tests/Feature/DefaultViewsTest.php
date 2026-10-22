<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DefaultViewsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $editor;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->editor = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->project->setRole($this->editor, ProjectRole::Editor);
        $this->project->setRole($this->manager, ProjectRole::Admin);
    }

    private function board(?User $as = null, array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($as ?? $this->editor)->test('pages::projects.board', ['project' => $this->project]);
    }

    private function list(?User $as = null, array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($as ?? $this->editor)->test('pages::projects.show', ['project' => $this->project]);
    }

    private function savedView(string $kind, string $name, string $sort, bool $shared = false, bool $default = false): SavedFilter
    {
        return SavedFilter::factory()->for($this->project)->create([
            'user_id' => $shared ? null : $this->editor->id,
            'name' => $name,
            'view' => $kind,
            'is_default' => $default,
            'filters' => ['sort' => $sort, 'direction' => 'desc'] + ($kind === SavedFilter::LIST ? ['status' => 'all', 'assignee' => '', 'tag' => '', 'fields' => []] : []),
        ]);
    }

    public function test_the_board_saves_its_sort_order_privately_or_for_everybody(): void
    {
        $this->board()->call('sort', 'due')->call('reverseSort')->set('saveName', 'Bald fällig')->call('saveCurrentFilter')->assertHasNoErrors();
        $this->board($this->manager)->call('sort', 'title')->set('saveName', 'Alphabetisch')->set('saveShared', true)->call('saveCurrentFilter');

        $mine = SavedFilter::where('name', 'Bald fällig')->firstOrFail();
        $this->assertSame(SavedFilter::BOARD, $mine->view);
        $this->assertSame($this->editor->id, $mine->user_id);
        $this->assertSame(['sort' => 'due', 'direction' => 'desc'], $mine->filters);
        $this->assertTrue(SavedFilter::where('name', 'Alphabetisch')->firstOrFail()->isShared());

        // the other person sees the shared one, not the private one
        $this->assertSame(['Alphabetisch'], $this->board($this->manager)->instance()->savedFilters->pluck('name')->diff(['Bald fällig'])->values()->all());
        $this->assertEqualsCanonicalizing(['Bald fällig', 'Alphabetisch'], $this->board()->instance()->savedFilters->pluck('name')->all());
    }

    public function test_the_list_and_the_board_keep_their_own_views(): void
    {
        $this->savedView(SavedFilter::LIST, 'Liste', 'due');
        $this->savedView(SavedFilter::BOARD, 'Board', 'title');

        $this->assertSame(['Liste'], $this->list()->instance()->savedFilters->pluck('name')->all());
        $this->assertSame(['Board'], $this->board()->instance()->savedFilters->pluck('name')->all());
    }

    public function test_applying_a_view_sorts_the_board_and_skips_what_does_not_exist(): void
    {
        $saved = $this->savedView(SavedFilter::BOARD, 'Nach Titel', 'title');
        $broken = $this->savedView(SavedFilter::BOARD, 'Kaputt', 'field:99999');

        $this->board()->call('applyFilter', $saved->id)->assertSet('sortBy', 'title')->assertSet('sortDirection', 'desc')
            ->call('applyFilter', $broken->id)->assertSet('sortBy', '');
    }

    public function test_the_default_of_the_project_opens_when_the_address_has_no_settings(): void
    {
        $this->savedView(SavedFilter::BOARD, 'Für alle', 'title', shared: true, default: true);
        $this->savedView(SavedFilter::LIST, 'Liste für alle', 'due', shared: true, default: true);

        $this->board()->assertSet('sortBy', 'title');
        $this->list()->assertSet('sortBy', 'due')->assertSet('statusFilter', 'all');

        // with a setting in the address, the person asked for something else
        $this->board(query: ['sort' => 'due'])->assertSet('sortBy', 'due');
        $this->list(query: ['status' => 'open'])->assertSet('sortBy', '');
    }

    public function test_the_own_default_comes_before_the_one_of_the_project(): void
    {
        $this->savedView(SavedFilter::BOARD, 'Für alle', 'title', shared: true, default: true);
        $mine = $this->savedView(SavedFilter::BOARD, 'Meine', 'due');

        $this->board()->call('useAsMyDefault', $mine->id)->assertHasNoErrors();
        $this->assertSame($mine->id, $this->editor->fresh()->defaultSavedView($this->project->id, SavedFilter::BOARD));

        $this->board()->assertSet('sortBy', 'due');
        // the others still get the one of the project
        $this->board($this->manager)->assertSet('sortBy', 'title');
    }

    public function test_a_person_can_switch_the_default_of_the_project_off_for_themselves_and_on_again(): void
    {
        $this->savedView(SavedFilter::BOARD, 'Für alle', 'title', shared: true, default: true);

        $this->board()->call('useNoDefaultForMe');
        $this->board()->assertSet('sortBy', '');

        $this->board()->call('followProjectDefault');
        $this->assertNull($this->editor->fresh()->defaultSavedView($this->project->id, SavedFilter::BOARD));
        $this->board()->assertSet('sortBy', 'title');
    }

    public function test_a_default_that_was_deleted_falls_back_to_the_one_of_the_project(): void
    {
        $this->savedView(SavedFilter::BOARD, 'Für alle', 'title', shared: true, default: true);
        $mine = $this->savedView(SavedFilter::BOARD, 'Meine', 'due');

        $this->board()->call('useAsMyDefault', $mine->id);
        $this->board()->call('deleteFilter', $mine->id);

        $this->board()->assertSet('sortBy', 'title');
    }

    public function test_only_managers_make_a_shared_view_the_default_for_everybody_and_there_is_one_per_view(): void
    {
        $first = $this->savedView(SavedFilter::BOARD, 'Eins', 'title', shared: true);
        $second = $this->savedView(SavedFilter::BOARD, 'Zwei', 'due', shared: true);
        $list = $this->savedView(SavedFilter::LIST, 'Liste', 'due', shared: true, default: true);
        $private = $this->savedView(SavedFilter::BOARD, 'Privat', 'due');

        $this->board()->call('makeProjectDefault', $first->id)->assertForbidden();

        $this->board($this->manager)->call('makeProjectDefault', $first->id)->assertHasNoErrors();
        $this->board($this->manager)->call('makeProjectDefault', $second->id);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        // the list keeps its own
        $this->assertTrue($list->fresh()->is_default);

        // a private view cannot be made the default for everybody, nor one of the other kind
        $this->board($this->manager)->call('makeProjectDefault', $private->id)->assertNotFound();
        $this->board($this->manager)->call('makeProjectDefault', $list->id)->assertNotFound();

        $this->board()->call('clearProjectDefault')->assertForbidden();
        $this->board($this->manager)->call('clearProjectDefault');
        $this->assertFalse($second->fresh()->is_default);
    }

    public function test_only_a_view_one_can_see_becomes_the_own_default(): void
    {
        $theirs = SavedFilter::factory()->for($this->project)->create(['user_id' => $this->manager->id, 'view' => SavedFilter::BOARD]);
        $list = $this->savedView(SavedFilter::LIST, 'Liste', 'due');

        $this->board()->call('useAsMyDefault', $theirs->id)->assertNotFound();
        $this->board()->call('useAsMyDefault', $list->id)->assertNotFound();

        $this->assertNull($this->editor->fresh()->defaultSavedView($this->project->id, SavedFilter::BOARD));
    }

    public function test_saving_a_view_again_keeps_its_default_flag(): void
    {
        $this->savedView(SavedFilter::BOARD, 'Für alle', 'title', shared: true, default: true);

        $this->board($this->manager)->call('sort', 'due')->set('saveName', 'Für alle')->set('saveShared', true)->call('saveCurrentFilter');

        $saved = SavedFilter::where('name', 'Für alle')->firstOrFail();
        $this->assertTrue($saved->is_default);
        $this->assertSame('due', $saved->filters['sort']);
    }

    public function test_the_own_defaults_of_a_person_are_kept_per_project_and_view_and_stay_small(): void
    {
        $user = User::factory()->create();

        $user->rememberDefaultSavedView(1, SavedFilter::LIST, 5);
        $user->rememberDefaultSavedView(1, SavedFilter::BOARD, 0);
        $user->rememberDefaultSavedView(2, SavedFilter::BOARD, 7);

        $this->assertSame(5, $user->defaultSavedView(1, SavedFilter::LIST));
        $this->assertSame(0, $user->defaultSavedView(1, SavedFilter::BOARD));
        $this->assertNull($user->defaultSavedView(3, SavedFilter::BOARD));

        $user->rememberDefaultSavedView(1, SavedFilter::BOARD, null);
        $user->rememberDefaultSavedView(2, SavedFilter::BOARD, null);

        $this->assertNull($user->defaultSavedView(1, SavedFilter::BOARD));
        $this->assertSame(5, $user->defaultSavedView(1, SavedFilter::LIST));
        $this->assertArrayNotHasKey(2, $user->fresh()->interfaceState()['default_views']);

        foreach (range(1, User::INTERFACE_MAX_BOARDS + 3) as $project) {
            $user->rememberDefaultSavedView(100 + $project, SavedFilter::LIST, 1);
        }

        $this->assertCount(User::INTERFACE_MAX_BOARDS, $user->fresh()->interfaceState()['default_views']);
    }

    public function test_the_menu_of_a_view_offers_the_defaults(): void
    {
        $saved = $this->savedView(SavedFilter::BOARD, 'Gemeinsam', 'title', shared: true);

        $this->board()->assertSee('Gemeinsam')->assertSee('Standardmäßig für mich öffnen')->assertDontSee('Standardmäßig für alle öffnen');
        $this->board($this->manager)->assertSee('Standardmäßig für alle öffnen')
            ->call('makeProjectDefault', $saved->id)->assertSee('Standard für alle')->assertSee('Standard für alle entfernen');
        $this->board()->assertSee('Wird standardmäßig geöffnet: „Gemeinsam“')->assertSee('Nicht für mich');
    }
}
