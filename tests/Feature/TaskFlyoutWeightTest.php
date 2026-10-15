<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Project;
use App\Models\Reaction;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Opening the task flyout stays light: the lists of the pickers and the people for @ are sent when they are used, and
 * the flyout asks the database for little.
 */
class TaskFlyoutWeightTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Project $project;

    private Task $task;

    /** @var Collection<int, User> */
    private Collection $members;

    /** @var Collection<int, Tag> */
    private Collection $tags;

    /** @var Collection<int, Task> */
    private Collection $others;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Mira Editor']);
        $this->project = Project::factory()->create();
        $this->project->setRole($this->me, ProjectRole::Editor);

        $this->members = collect(range(1, 30))->map(function (int $number) {
            $member = User::factory()->create(['name' => sprintf('Mitglied %02d Ypsilon', $number)]);
            $this->project->setRole($member, ProjectRole::Editor);

            return $member;
        });
        $this->tags = collect(range(1, 8))->map(fn (int $number) => Tag::factory()->for($this->project)->create(['name' => sprintf('Etikett %d Zeta', $number)]));
        $this->others = collect(range(1, 40))->map(fn (int $number) => Task::factory()->for($this->project)->create(['title' => sprintf('Fernes Thema %02d Omega', $number), 'creator_id' => $this->me->id]));

        $priority = CustomField::factory()->for($this->project)->select()->create(['name' => 'Priorität']);
        CustomFieldOption::create(['custom_field_id' => $priority->id, 'name' => 'Hoch', 'position' => 0]);
        CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);

        $this->task = Task::factory()->for($this->project)->create(['creator_id' => $this->me->id, 'title' => 'Das Angebot', 'assignee_id' => $this->members[0]->id]);
        $subtasks = Task::factory()->count(5)->for($this->project)->create(['parent_id' => $this->task->id, 'creator_id' => $this->me->id]);
        Task::factory()->count(3)->for($this->project)->create(['parent_id' => $subtasks[0]->id, 'creator_id' => $this->me->id]);
        $this->task->update(['parent_id' => $this->others[0]->id]);
        $this->task->tags()->sync([$this->tags[0]->id, $this->tags[1]->id]);
        $this->task->collaborators()->sync([$this->members[1]->id]);
        $this->task->blockers()->sync([$this->others[1]->id]);
        $this->task->blocking()->sync([$this->others[2]->id]);

        foreach (Comment::factory()->count(10)->for($this->task)->create(['user_id' => $this->members[2]->id]) as $comment) {
            Reaction::create(['user_id' => $this->members[3]->id, 'reactable_type' => Comment::class, 'reactable_id' => $comment->id, 'emoji' => '👍']);
        }

        $this->actingAs($this->me);
    }

    private function page(): Testable
    {
        return Livewire::test('pages::tasks.show', ['task' => $this->task]);
    }

    public function test_the_pickers_hold_only_what_is_chosen_until_they_are_loaded(): void
    {
        $page = $this->page();

        // What is chosen is shown right away ...
        $page->assertSee('Mitglied 01 Ypsilon')->assertSee('Mitglied 02 Ypsilon')
            ->assertSee('Etikett 1 Zeta')->assertSee('Etikett 2 Zeta')
            ->assertSee('Fernes Thema 01 Omega')->assertSee('Fernes Thema 02 Omega')->assertSee('Fernes Thema 03 Omega');

        // ... but not what could be chosen
        $page->assertDontSee('Mitglied 20 Ypsilon')->assertDontSee('Etikett 5 Zeta')->assertDontSee('Fernes Thema 20 Omega');
        $this->assertSame([], $page->get('loadedPickers'));
        $this->assertCount(1, $page->instance()->assigneeOptions);
        $this->assertCount(1, $page->instance()->collaboratorOptions);
        $this->assertCount(2, $page->instance()->tagOptions);
        $this->assertCount(1, $page->instance()->parentOptions);
        $this->assertCount(1, $page->instance()->blockerOptions);
        $this->assertCount(1, $page->instance()->blockingOptions);
    }

    /**
     * What the last request sent as the new choices of the pickers (island fragments), and whether the page was rendered whole.
     */
    private function choicesSent(Testable $page): string
    {
        return implode('', $page->effects['islandFragments'] ?? []);
    }

    public function test_ids_from_the_browser_do_not_show_people_the_page_would_not_offer(): void
    {
        $outsider = User::factory()->create(['name' => 'Geheime Fremde Person']);

        $page = $this->page()->set('assigneeId', (string) $outsider->id)->set('collaboratorIds', [(string) $outsider->id]);

        $page->assertDontSee('Geheime Fremde Person');
        $this->assertCount(0, $page->instance()->assigneeOptions);
        $this->assertCount(0, $page->instance()->collaboratorOptions);
    }

    public function test_a_picker_sends_its_list_after_its_trigger_and_only_its_own(): void
    {
        $page = $this->page()->call('loadPicker', 'people');

        $choices = $this->choicesSent($page);

        // Only the lists of the picker, not the page: comments, activity and subtasks stay where they are
        $this->assertArrayNotHasKey('html', $page->effects);
        $this->assertStringContainsString('Mitglied 20 Ypsilon', $choices);
        $this->assertStringNotContainsString('Etikett 5 Zeta', $choices);
        $this->assertStringNotContainsString('Fernes Thema 20 Omega', $choices);
        $this->assertStringNotContainsString('data-comment-card', $choices);
        $this->assertSame(2, substr_count($choices, 'ENDFRAGMENT'));
        $this->assertStringContainsString('name=assignee-choices', $choices);
        $this->assertStringContainsString('name=collaborator-choices', $choices);
        $this->assertCount(31, $page->instance()->assigneeOptions);
        $this->assertCount(31, $page->instance()->collaboratorOptions);

        $tags = $this->choicesSent($page->call('loadPicker', 'tags'));
        $this->assertStringContainsString('Etikett 5 Zeta', $tags);
        $this->assertStringNotContainsString('Mitglied 20 Ypsilon', $tags);
        $this->assertCount(8, $page->instance()->tagOptions);

        $parent = $this->choicesSent($page->call('loadPicker', 'parent'));
        $this->assertStringContainsString('Fernes Thema 20 Omega', $parent);
        $this->assertSame(['people', 'tags', 'parent'], $page->get('loadedPickers'));
        $this->assertCount(40, $page->instance()->parentOptions);
        $this->assertCount(1, $page->instance()->blockerOptions);
    }

    public function test_the_loaded_choices_stay_when_the_page_is_rendered_whole_again(): void
    {
        $page = $this->page()->call('loadPicker', 'people')->call('loadPicker', 'tags');

        $page->call('$refresh')->assertSee('Mitglied 20 Ypsilon')->assertSee('Etikett 5 Zeta')->assertDontSee('Fernes Thema 20 Omega');
    }

    public function test_pointing_at_a_field_loads_nothing_only_using_it_does(): void
    {
        $html = $this->page()->html();

        $this->assertStringContainsString('x-data="lazyPicker(', $html);
        $this->assertStringContainsString('x-on:pointerdown="load()" x-on:focusin="load()"', $html);
        $this->assertStringNotContainsString('x-on:pointerenter', $html);
    }

    public function test_loading_a_picker_twice_or_an_unknown_one_changes_nothing(): void
    {
        $page = $this->page()->call('loadPicker', 'tags')->call('loadPicker', 'tags')->call('loadPicker', 'secrets');

        $this->assertSame(['tags'], $page->get('loadedPickers'));
    }

    public function test_the_picker_lists_cannot_be_set_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->page()->set('loadedPickers', ['people']);
    }

    public function test_choosing_and_saving_work_after_the_lists_were_loaded(): void
    {
        $page = $this->page();

        foreach (['people', 'tags', 'parent', 'blockers', 'blocking'] as $picker) {
            $page->call('loadPicker', $picker);
        }

        $page->set('assigneeId', (string) $this->members[10]->id)
            ->set('collaboratorIds', [(string) $this->members[11]->id, (string) $this->members[12]->id])
            ->set('tagIds', [(string) $this->tags[4]->id])
            ->set('parentId', (string) $this->others[20]->id)
            ->set('blockerIds', [(string) $this->others[21]->id])
            ->set('blockingIds', [(string) $this->others[22]->id])
            ->call('save')
            ->assertHasNoErrors();

        $task = $this->task->fresh();
        $this->assertSame($this->members[10]->id, $task->assignee_id);
        $this->assertSame($this->others[20]->id, $task->parent_id);
        $this->assertEqualsCanonicalizing([$this->members[11]->id, $this->members[12]->id], $task->collaborators()->pluck('users.id')->all());
        $this->assertSame([$this->tags[4]->id], $task->tags()->pluck('tags.id')->all());
        $this->assertSame([$this->others[21]->id], $task->blockers()->pluck('tasks.id')->all());
        $this->assertSame([$this->others[22]->id], $task->blocking()->pluck('tasks.id')->all());

        // What was chosen shows in the lists
        $page->assertSee('Mitglied 11 Ypsilon')->assertSee('Etikett 5 Zeta');
    }

    public function test_saving_without_any_list_keeps_what_is_chosen(): void
    {
        $this->page()->set('title', 'Neuer Titel')->call('save')->assertHasNoErrors();

        $task = $this->task->fresh();
        $this->assertSame('Neuer Titel', $task->title);
        $this->assertSame($this->members[0]->id, $task->assignee_id);
        $this->assertSame($this->others[0]->id, $task->parent_id);
        $this->assertEqualsCanonicalizing([$this->tags[0]->id, $this->tags[1]->id], $task->tags()->pluck('tags.id')->all());
        $this->assertSame([$this->members[1]->id], $task->collaborators()->pluck('users.id')->all());
        $this->assertSame([$this->others[1]->id], $task->blockers()->pluck('tasks.id')->all());
        $this->assertSame([$this->others[2]->id], $task->blocking()->pluck('tasks.id')->all());
    }

    public function test_a_tag_made_in_the_flyout_shows_without_the_list_having_been_loaded(): void
    {
        $page = $this->page()->set('newTag', 'Brandneu')->call('createTag');

        $this->assertContains('tags', $page->get('loadedPickers'));
        $page->assertSee('Brandneu');
    }

    public function test_the_person_taking_the_task_by_shortcut_shows_as_the_assignee_without_the_list(): void
    {
        $page = $this->page()->dispatch('shortcut-assign-me');

        $this->assertSame($this->me->id, $this->task->fresh()->assignee_id);
        $this->assertSame([$this->me->id], $page->instance()->assigneeOptions->pluck('id')->all());
    }

    public function test_the_people_for_mentions_are_not_part_of_the_page_but_come_on_request(): void
    {
        $page = $this->page();

        // Three editors (description, comment, and the one for editing a comment when it is open) without any of the 31 people
        $page->assertDontSee('Mitglied 20 Ypsilon')->assertSeeHtml('x-on:focusin.once="loadPeople()"');
        $this->assertStringNotContainsString('Mitglied 20 Ypsilon', $page->html());

        $page->call('mentionPeople')->assertReturned(fn (array $people) => count($people) === 31
            && collect($people)->pluck('name')->contains('Mitglied 20 Ypsilon')
            && array_keys($people[0]) === ['id', 'name', 'note', 'initials', 'avatar', 'color']);
    }

    public function test_the_mention_people_leave_out_people_who_cannot_sign_in(): void
    {
        $this->members[5]->update(['deactivated_at' => now()]);

        $page = $this->page();

        $page->call('mentionPeople')->assertReturned(fn (array $people) => collect($people)->pluck('name')->doesntContain('Mitglied 06 Ypsilon'));
    }

    public function test_viewers_get_the_chosen_values_too(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        Livewire::actingAs($viewer)->test('pages::tasks.show', ['task' => $this->task])
            ->assertSee('Mitglied 01 Ypsilon')->assertSee('Etikett 1 Zeta')->assertSee('Fernes Thema 02 Omega');
    }

    public function test_the_flyout_asks_the_database_for_little_and_stays_small(): void
    {
        $flyout = Livewire::test('task-flyout');

        // Rendering once before: the first request of a test boots things that no flyout opening pays for
        Livewire::test('task-flyout')->call('openTask', $this->others[5]->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $flyout->call('openTask', $this->task->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $flyout->assertSet('openTaskId', (string) $this->task->id)->assertSeeHtml('data-modal="task-panel"');

        // Was 44 queries and about 590 KB of HTML for the same task before the lists and menus came on demand
        $this->assertLessThanOrEqual(32, count($queries), collect($queries)->pluck('query')->implode("\n"));
        $this->assertLessThan(400_000, strlen($flyout->html(stripInitialData: true)));

        // None of them reads the people, tags or tasks the pickers would offer
        $this->assertSame([], collect($queries)->pluck('query')->filter(fn (string $query) => str_contains($query, 'limit 50') && str_contains($query, '"tasks"') && str_contains($query, 'order by "title"'))->all());
        $this->assertSame([], collect($queries)->pluck('query')->filter(fn (string $query) => str_contains($query, 'from "tags" where "tags"."project_id"'))->all());
    }

    public function test_opening_a_task_one_may_not_see_keeps_the_open_one(): void
    {
        $hidden = Task::factory()->for(Project::factory()->create())->create();

        Livewire::test('task-flyout')
            ->call('openTask', $this->task->id)->assertSet('openTaskId', (string) $this->task->id)
            ->call('openTask', $hidden->id)->assertSet('openTaskId', (string) $this->task->id)
            ->call('openTask', 'nonsense')->assertSet('openTaskId', (string) $this->task->id)
            ->call('openTask', $this->others[0]->id)->assertSet('openTaskId', (string) $this->others[0]->id);
    }

    public function test_the_reaction_menu_is_sent_once_not_for_every_comment(): void
    {
        $html = $this->page()->html();

        $this->assertSame(1, substr_count($html, '<template data-reaction-menu>'));
        $this->assertSame(11, substr_count($html, 'x-html="menuHtml()"'));
    }
}
