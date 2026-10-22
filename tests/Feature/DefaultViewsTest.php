<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function board(?User $as = null, array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->actingAs($as ?? $this->editor)->test('pages::projects.board', ['project' => $this->project]);
    }

    private function list(?User $as = null, array $query = []): Testable
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

        // what the address says comes before the default, setting by setting; the rest of the default stays
        $this->board(query: ['sort' => 'due'])->assertSet('sortBy', 'due')->assertSet('sortDirection', 'desc');
        $this->board(query: ['dir' => 'asc'])->assertSet('sortBy', 'title')->assertSet('sortDirection', 'asc');
        $this->board(query: ['sort' => ''])->assertSet('sortBy', '');
        $this->list(query: ['status' => 'open'])->assertSet('statusFilter', 'open')->assertSet('sortBy', 'due');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function listAddressKeys(): array
    {
        return [
            'status' => ['status', 'statusFilter'], 'assignee' => ['assignee', 'assigneeFilter'], 'tag' => ['tag', 'tagFilter'],
            'dates' => ['dates', 'dateFilter'], 'sort' => ['sort', 'sortBy'], 'dir' => ['dir', 'sortDirection'],
        ];
    }

    #[DataProvider('listAddressKeys')]
    public function test_every_setting_of_the_address_of_the_list_comes_before_the_default(string $key, string $property): void
    {
        $this->savedView(SavedFilter::LIST, 'Für alle', 'due', shared: true, default: true);
        $value = ['status' => 'open', 'assignee' => 'me', 'tag' => '', 'dates' => 'overdue', 'sort' => 'title', 'dir' => 'asc'][$key];

        $this->list(query: [$key => $value])->assertSet($property, $value);
    }

    public function test_a_default_of_the_list_leaves_out_what_no_longer_exists(): void
    {
        $this->savedView(SavedFilter::LIST, 'Alt', 'field:99999', shared: true, default: true)->update(['filters' => [
            'status' => '99999', 'assignee' => '99999', 'tag' => '99999', 'fields' => ['99999' => '1', '7' => ['x']], 'sort' => 'field:99999', 'direction' => 'desc',
        ]]);

        $this->list()->assertSet('statusFilter', 'open')->assertSet('assigneeFilter', '')->assertSet('tagFilter', '')
            ->assertSet('fieldFilters', [])->assertSet('sortBy', '');
    }

    public function test_only_a_sort_order_that_is_in_force_is_saved_and_fields_must_be_single_values(): void
    {
        $this->list()->set('sortDirection', 'desc')->set('saveName', 'Ohne Sortierung')->call('saveCurrentFilter');
        $this->list()->set('fieldFilters.3', ['x'])->set('saveName', 'Feld')->call('saveCurrentFilter');

        $this->assertSame('', SavedFilter::where('name', 'Ohne Sortierung')->firstOrFail()->filters['sort']);
        $this->assertSame([], SavedFilter::where('name', 'Feld')->firstOrFail()->filters['fields']);
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

    public function test_the_menus_of_the_dialog_choose_what_opens_by_default(): void
    {
        $shared = $this->savedView(SavedFilter::BOARD, 'Gemeinsam', 'title', shared: true);
        $mine = $this->savedView(SavedFilter::BOARD, 'Meine', 'due');

        // a person who does not manage the project has the menu for themselves only
        $this->board()->assertSee('Beim Öffnen anwenden')->assertSee('Wie im Projekt vorgegeben: nichts')->assertDontSee('Beim Öffnen anwenden, für alle im Projekt')
            ->assertSet('defaultChoice', '')
            ->set('defaultChoice', (string) $mine->id)->assertHasNoErrors();
        $this->assertSame($mine->id, $this->editor->fresh()->defaultSavedView($this->project->id, SavedFilter::BOARD));
        $this->board()->assertSet('defaultChoice', (string) $mine->id)->assertSet('sortBy', 'due')->assertSee('Für dich angewendet');

        $this->board()->set('defaultChoice', 'none');
        $this->assertSame(0, $this->editor->fresh()->defaultSavedView($this->project->id, SavedFilter::BOARD));
        $this->board()->assertSet('defaultChoice', 'none')->assertSet('sortBy', '');

        $this->board()->set('defaultChoice', '');
        $this->assertNull($this->editor->fresh()->defaultSavedView($this->project->id, SavedFilter::BOARD));

        // a manager also chooses what opens for everybody, among the shared views
        $this->board($this->manager)->assertSee('Beim Öffnen anwenden, für alle im Projekt')->assertSet('projectChoice', 'none')
            ->set('projectChoice', (string) $shared->id);
        $this->assertTrue($shared->fresh()->is_default);

        $this->board()->assertSee('Wie im Projekt vorgegeben: „Gemeinsam“')->assertSee('Vorgabe für alle')->assertSet('sortBy', 'title');

        $this->board($this->manager)->set('projectChoice', 'none');
        $this->assertFalse($shared->fresh()->is_default);
    }

    public function test_a_person_who_does_not_manage_the_project_cannot_set_the_default_for_everybody(): void
    {
        $shared = $this->savedView(SavedFilter::BOARD, 'Gemeinsam', 'title', shared: true);

        $this->board()->set('projectChoice', (string) $shared->id)->assertForbidden();
        $this->board()->set('projectChoice', 'none')->assertForbidden();

        $this->assertFalse($shared->fresh()->is_default);
    }

    public function test_choices_that_are_not_valid_are_ignored(): void
    {
        $theirs = SavedFilter::factory()->for($this->project)->create(['user_id' => $this->manager->id, 'view' => SavedFilter::BOARD]);

        $this->board()->set('defaultChoice', 'banana')->assertSet('defaultChoice', '');
        $this->board()->set('defaultChoice', (string) $theirs->id)->assertNotFound();
        $this->board($this->manager)->set('projectChoice', 'banana')->assertSet('projectChoice', 'none');

        $this->assertNull($this->editor->fresh()->defaultSavedView($this->project->id, SavedFilter::BOARD));
    }

    public function test_a_default_of_the_person_that_was_deleted_leaves_the_project_default_to_switch_off(): void
    {
        $this->savedView(SavedFilter::BOARD, 'Für alle', 'title', shared: true, default: true);
        $mine = $this->savedView(SavedFilter::BOARD, 'Meine', 'due');

        $this->board()->call('useAsMyDefault', $mine->id)->call('deleteFilter', $mine->id)
            ->assertSet('defaultChoice', '')->assertSee('Wie im Projekt vorgegeben: „Für alle“');
    }

    public function test_switching_the_default_off_for_oneself_can_be_taken_back_without_a_project_default(): void
    {
        $this->board()->call('useNoDefaultForMe')->assertSet('defaultChoice', 'none')->call('followProjectDefault')->assertSet('defaultChoice', '');

        $this->assertNull($this->editor->fresh()->defaultSavedView($this->project->id, SavedFilter::BOARD));
    }

    public function test_deleting_the_default_of_the_project_leaves_none(): void
    {
        $default = SavedFilter::factory()->for($this->project)->board()->projectDefault()->create(['name' => 'Weg']);

        $this->board($this->manager)->call('deleteFilter', $default->id);

        $this->board()->assertSet('sortBy', '');
        $this->assertNull($this->board()->instance()->savedViewDefaults['project']);
    }

    public function test_a_viewer_can_pick_a_shared_view_as_their_default(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $shared = $this->savedView(SavedFilter::BOARD, 'Gemeinsam', 'title', shared: true);

        $this->board($viewer)->call('useAsMyDefault', $shared->id)->assertHasNoErrors();
        $this->board($viewer)->assertSet('sortBy', 'title');
    }

    public function test_views_of_another_project_or_kind_cannot_be_applied_or_deleted(): void
    {
        $other = SavedFilter::factory()->for(Project::factory()->create())->shared()->create();
        $list = $this->savedView(SavedFilter::LIST, 'Liste', 'due');

        $this->board()->call('applyFilter', $other->id)->assertNotFound();
        $this->board()->call('deleteFilter', $list->id)->assertNotFound();
        $this->list()->call('deleteFilter', $other->id)->assertNotFound();
    }

    public function test_the_factory_states_and_the_old_rows_count_as_list_views(): void
    {
        $this->assertSame(SavedFilter::LIST, SavedFilter::factory()->for($this->project)->create()->view);
        $this->assertFalse(SavedFilter::factory()->for($this->project)->create()->is_default);
        $this->assertTrue(SavedFilter::factory()->for($this->project)->projectDefault()->create()->is_default);
    }

    public function test_a_board_that_opens_with_a_default_sort_only_moves_cards_between_columns(): void
    {
        $this->savedView(SavedFilter::BOARD, 'Für alle', 'title', shared: true, default: true);
        $board = $this->board()->assertSet('sortBy', 'title');

        $this->assertTrue($board->instance()->sortActive);
    }

    public function test_making_the_view_that_is_the_default_the_default_again_keeps_it(): void
    {
        $saved = $this->savedView(SavedFilter::BOARD, 'Für alle', 'title', shared: true);

        // the second manager's page was loaded before the first one made it the default
        $stale = $this->board($this->manager);
        $this->board($this->manager)->call('makeProjectDefault', $saved->id);
        $stale->call('makeProjectDefault', $saved->id)->call('makeProjectDefault', $saved->id);

        $this->assertTrue($saved->fresh()->is_default);
    }
}
