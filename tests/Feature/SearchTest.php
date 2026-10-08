<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
        $this->project = Project::factory()->create(['name' => 'Website']);
    }

    private function task(string $title, array $attributes = [], ?Project $project = null): Task
    {
        return Task::factory()->for($project ?? $this->project)->create($attributes + ['title' => $title, 'description' => null]);
    }

    /**
     * @return list<string>
     */
    private function titles(string $query, array $filters = [], ?User $user = null): array
    {
        return app(TaskSearchService::class)->search($user ?? $this->user, $query, $filters)->pluck('title')->all();
    }

    private function withoutFullText(): void
    {
        Schema::dropIfExists(TaskSearchService::TABLE);
        $this->app->forgetInstance(TaskSearchService::class);
    }

    public function test_the_migration_creates_the_fts5_index_here(): void
    {
        $this->assertTrue(app(TaskSearchService::class)->usesFullText());
    }

    public function test_titles_descriptions_comments_and_attachment_names_are_searched(): void
    {
        $this->task('Angebot schreiben');
        $this->task('Aufräumen', ['description' => 'Das Lager muss dringend sortiert werden']);
        $commented = $this->task('Telefonat');
        Comment::factory()->create(['task_id' => $commented->id, 'user_id' => $this->user->id, 'body' => 'Kunde wünscht Rückruf']);
        $attached = $this->task('Unterlagen');
        Attachment::factory()->create(['task_id' => $attached->id, 'name' => 'Steuererklärung.pdf']);

        $this->assertSame(['Angebot schreiben'], $this->titles('angebot'));
        $this->assertSame(['Aufräumen'], $this->titles('lager'));
        $this->assertSame(['Telefonat'], $this->titles('rückruf'));
        $this->assertSame(['Unterlagen'], $this->titles('steuererklärung'));
    }

    public function test_all_words_must_match_and_word_beginnings_are_enough(): void
    {
        $this->task('Monatsbericht erstellen');
        $this->task('Monatsplanung');

        $this->assertEqualsCanonicalizing(['Monatsbericht erstellen', 'Monatsplanung'], $this->titles('monat'));
        $this->assertSame(['Monatsbericht erstellen'], $this->titles('monat erstell'));
        $this->assertSame([], $this->titles('monat gibtsnicht'));
    }

    public function test_diacritics_and_case_do_not_matter_with_fts5(): void
    {
        $this->task('Übersicht für Müller');

        $this->assertSame(['Übersicht für Müller'], $this->titles('UBERSICHT muller'));
    }

    public function test_titles_rank_above_other_hits(): void
    {
        $this->task('Allgemeines', ['description' => 'Thema Budget wird hier besprochen']);
        $this->task('Budget planen');

        $this->assertSame(['Budget planen', 'Allgemeines'], $this->titles('budget'));
    }

    public function test_special_characters_in_the_query_are_harmless(): void
    {
        $this->task('Bericht Q3');

        foreach (['"', 'Bericht"', 'bericht AND OR NOT', 'bericht*', '(bericht)', "bericht'; drop table tasks;--", 'col:bericht', '-bericht'] as $query) {
            $this->assertIsArray($this->titles($query), $query);
        }

        $this->assertSame([], $this->titles('   '));
        $this->assertSame([], $this->titles('"*"'));
    }

    public function test_the_index_follows_changes(): void
    {
        $task = $this->task('Alt');
        $comment = Comment::factory()->create(['task_id' => $task->id, 'user_id' => $this->user->id, 'body' => 'Kommentartext']);
        $attachment = Attachment::factory()->create(['task_id' => $task->id, 'name' => 'anhang.pdf']);

        $task->update(['title' => 'Neuer Titel', 'description' => 'frische Beschreibung']);
        $this->assertSame(['Neuer Titel'], $this->titles('neuer'));
        $this->assertSame([], $this->titles('alt'));
        $this->assertSame(['Neuer Titel'], $this->titles('frische'));

        $comment->update(['body' => 'geänderter Kommentar']);
        $this->assertSame([], $this->titles('kommentartext'));
        $this->assertSame(['Neuer Titel'], $this->titles('geänderter'));

        $comment->delete();
        $this->assertSame([], $this->titles('geänderter'));

        $attachment->delete();
        $this->assertSame([], $this->titles('anhang'));

        $task->delete();
        $this->assertSame([], $this->titles('neuer'));
        $this->assertSame(0, DB::table(TaskSearchService::TABLE)->count());
    }

    public function test_deleting_a_parent_leaves_no_search_hits_for_its_subtasks(): void
    {
        $parent = $this->task('Eltern');
        $this->task('Kindaufgabe', ['parent_id' => $parent->id]);

        $parent->delete();

        $this->assertSame([], $this->titles('kindaufgabe'));
    }

    public function test_headings_are_not_found(): void
    {
        $this->task('Vorbereitung', ['is_section' => true]);

        $this->assertSame([], $this->titles('vorbereitung'));
    }

    public function test_only_visible_projects_are_searched(): void
    {
        $other = Project::factory()->create();
        $this->task('Geheim bei anderen', project: $other);
        $mine = $this->task('Geheim bei mir');
        $member = User::factory()->create();
        $this->project->setRole($member, ProjectRole::Viewer);

        $this->assertSame(['Geheim bei mir'], $this->titles('geheim', user: $member));
        $this->assertCount(2, $this->titles('geheim'));
        $this->assertNotNull($mine);
    }

    public function test_filters_for_project_state_and_own_tasks(): void
    {
        $other = Project::factory()->create();
        $this->task('Plan offen');
        $this->task('Plan fertig', ['status_id' => $this->project->doneStatus()->id]);
        $this->task('Plan meins', ['assignee_id' => $this->user->id]);
        $collab = $this->task('Plan geteilt');
        $collab->collaborators()->attach($this->user);
        $this->task('Plan woanders', project: $other);

        $this->assertCount(5, $this->titles('plan'));
        $this->assertCount(4, $this->titles('plan', ['project_id' => $this->project->id]));
        $this->assertNotContains('Plan fertig', $this->titles('plan', ['state' => 'open']));
        $this->assertSame(['Plan fertig'], $this->titles('plan', ['state' => 'done', 'project_id' => $this->project->id]));
        $this->assertEqualsCanonicalizing(['Plan meins', 'Plan geteilt'], $this->titles('plan', ['mine' => true]));
    }

    public function test_the_like_fallback_gives_the_same_answers(): void
    {
        $this->withoutFullText();
        $this->assertFalse(app(TaskSearchService::class)->usesFullText());

        $this->task('Angebot schreiben');
        $this->task('Aufräumen', ['description' => 'Das Lager muss sortiert werden']);
        $commented = $this->task('Telefonat');
        Comment::factory()->create(['task_id' => $commented->id, 'user_id' => $this->user->id, 'body' => 'Kunde wünscht Rückruf']);
        $attached = $this->task('Unterlagen');
        Attachment::factory()->create(['task_id' => $attached->id, 'name' => 'Steuer.pdf']);
        $this->task('Plan fertig', ['status_id' => $this->project->doneStatus()->id]);
        $this->task('Plan 100% sicher_');

        $this->assertSame(['Angebot schreiben'], $this->titles('angebot'));
        $this->assertSame(['Aufräumen'], $this->titles('lager'));
        $this->assertSame(['Telefonat'], $this->titles('rückruf'));
        $this->assertSame(['Unterlagen'], $this->titles('steuer'));
        $this->assertSame(['Plan fertig'], $this->titles('plan', ['state' => 'done']));
        $this->assertSame(['Plan 100% sicher_'], $this->titles('plan sicher'));
        $this->assertSame([], $this->titles('"'));
        $this->assertSame([], $this->titles('   '));
        $this->assertSame([], $this->titles('%'));
    }

    public function test_the_fallback_respects_visibility(): void
    {
        $this->withoutFullText();
        $this->task('Geheim', project: Project::factory()->create());

        $this->assertSame([], $this->titles('geheim', user: User::factory()->create()));
    }

    public function test_rebuild_restores_the_index(): void
    {
        $task = $this->task('Wiederfinden', ['description' => 'Beschreibung X']);
        DB::table(TaskSearchService::TABLE)->delete();
        $this->assertSame([], $this->titles('wiederfinden'));

        $this->artisan('search:rebuild')->assertSuccessful();

        $this->assertSame(['Wiederfinden'], $this->titles('beschreibung'));
        $this->assertNotNull($task);
    }

    public function test_rebuild_creates_a_missing_index_and_fills_it(): void
    {
        $this->task('Egal', ['description' => 'Inhalt']);
        $this->withoutFullText();
        $this->assertFalse(app(TaskSearchService::class)->usesFullText());

        $this->artisan('search:rebuild')->expectsOutputToContain('1 tasks indexed')->assertSuccessful();

        $this->assertTrue(app(TaskSearchService::class)->usesFullText());
        $this->assertSame(['Egal'], $this->titles('inhalt'));
    }

    public function test_the_page_shows_hits_with_context_and_respects_filters(): void
    {
        $this->task('Budget planen', ['assignee_id' => $this->user->id]);
        $this->task('Allgemeines', ['description' => 'Wir müssen über das Budget der Abteilung sprechen']);
        $this->task('Budget erledigt', ['status_id' => $this->project->doneStatus()->id]);

        Livewire::test('pages::search')
            ->assertSee('Gib einen Suchbegriff ein')
            ->set('query', 'budget')
            ->assertSee('Budget planen')
            ->assertSee('Allgemeines')
            ->assertSee('Beschreibung:')
            ->assertSee('Abteilung sprechen')
            ->set('state', 'open')
            ->assertDontSee('Budget erledigt')
            ->set('mine', true)
            ->assertSee('Budget planen')
            ->assertDontSee('Allgemeines')
            ->set('query', 'gibtsnicht')
            ->assertSee('Nichts gefunden');
    }

    public function test_the_page_shows_the_comment_or_attachment_that_matched(): void
    {
        $commented = $this->task('Telefonat');
        Comment::factory()->create(['task_id' => $commented->id, 'user_id' => $this->user->id, 'body' => 'Kunde wünscht dringend Rückruf']);
        $attached = $this->task('Unterlagen');
        Attachment::factory()->create(['task_id' => $attached->id, 'name' => 'Rechnung-2026.pdf']);

        Livewire::test('pages::search')->set('query', 'rückruf')->assertSee('Kommentar:')->assertSee('dringend Rückruf');
        Livewire::test('pages::search')->set('query', 'rechnung')->assertSee('Anhang:')->assertSee('Rechnung-2026.pdf');
    }

    public function test_the_page_is_reachable_from_the_header_and_through_the_url(): void
    {
        $this->task('Urlaubsplanung');

        $this->get(route('projects.index'))->assertOk()->assertSee(route('search'), false);
        $this->get(route('search', ['q' => 'urlaub']))->assertOk()->assertSee('Urlaubsplanung');

        auth()->logout();
        $this->get(route('search'))->assertRedirect(route('login'));
    }

    public function test_the_page_hides_foreign_projects(): void
    {
        $this->task('Fremd', project: Project::factory()->create());

        $this->actingAs(User::factory()->create());

        Livewire::test('pages::search')->set('query', 'fremd')->assertSee('Nichts gefunden')->assertDontSee('Fremd');
    }

    public function test_the_hit_excerpt_of_a_task_in_a_foreign_project_cannot_be_requested(): void
    {
        $foreign = $this->task('Geheim', ['description' => 'vertraulicher Inhalt'], Project::factory()->create());
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::search')
            ->set('query', 'vertraulicher')
            ->call('explain', $foreign->id)
            ->assertForbidden();
    }
}
