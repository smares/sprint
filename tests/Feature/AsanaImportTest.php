<?php

namespace Tests\Feature;

use App\Enums\CustomFieldType;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AsanaClient;
use App\Services\AsanaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Asana's API is answered from a small workspace: two projects, a task that lies in both, a subtask, dependencies,
 * comments, an attachment and people of whom one has no account here.
 */
class AsanaImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> Answers by path (and the workspace or parent they are asked for). */
    private array $routes = [];

    private User $admin;

    private User $anna;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        Notification::fake();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);
        $this->ben = User::factory()->create(['name' => 'Ben', 'email' => 'ben@example.com']);
        $this->defineRoutes();
    }

    private function person(string $gid, string $name, ?string $email): array
    {
        return array_filter(['gid' => $gid, 'name' => $name, 'email' => $email]);
    }

    private function task(string $gid, string $name, array $more = []): array
    {
        return array_merge(['gid' => $gid, 'name' => $name, 'notes' => '', 'html_notes' => null, 'completed' => false, 'num_subtasks' => 0, 'memberships' => [], 'followers' => [], 'dependencies' => [], 'tags' => [], 'custom_fields' => []], $more);
    }

    private function defineRoutes(): void
    {
        $priority = __('Priority');

        $this->routes = [
            'workspaces' => [['gid' => '900', 'name' => 'Firma']],
            'projects?workspace=900' => [['gid' => '100', 'name' => 'Website'], ['gid' => '200', 'name' => 'Marketing']],
            'users?workspace=900' => [$this->person('11', 'Anna', 'ANNA@example.com'), $this->person('12', 'Ben', 'Ben@Example.com'), $this->person('13', 'Cara', 'cara@example.com')],

            'projects/100' => ['gid' => '100', 'name' => 'Website', 'notes' => 'Der neue Auftritt', 'color' => 'dark-green', 'archived' => false, 'owner' => ['gid' => '11'], 'workspace' => ['gid' => '900'], 'members' => [$this->person('11', 'Anna', 'anna@example.com'), $this->person('12', 'Ben', 'ben@example.com'), $this->person('13', 'Cara', 'cara@example.com')]],
            'projects/100/sections' => [['gid' => '110', 'name' => 'Untitled section'], ['gid' => '111', 'name' => 'Backlog'], ['gid' => '112', 'name' => 'Doing']],
            'projects/100/custom_field_settings' => [
                ['custom_field' => ['gid' => '5001', 'name' => $priority, 'resource_subtype' => 'enum', 'enum_options' => [['name' => 'Low', 'enabled' => true], ['name' => 'High', 'enabled' => true], ['name' => 'Hidden', 'enabled' => false]]]],
                ['custom_field' => ['gid' => '5002', 'name' => 'Aufwand', 'resource_subtype' => 'number']],
                ['custom_field' => ['gid' => '5003', 'name' => 'Formel', 'resource_subtype' => 'reference']],
            ],
            'projects/100/tasks' => [
                $this->task('1001', 'Konzept', [
                    'html_notes' => '<body>Ein <strong>Plan</strong> für <A data-asana-gid="12" data-asana-type="user"/>, nach <a href="https://app.asana.com/0/100/1003">Umsetzung</a></body>',
                    'completed' => false, 'created_at' => '2026-09-01T08:00:00.000Z', 'modified_at' => '2026-09-02T09:30:00.000Z',
                    'due_on' => '2026-11-05', 'num_subtasks' => 1,
                    'assignee' => $this->person('11', 'Anna', 'anna@example.com'), 'created_by' => ['gid' => '12', 'name' => 'Ben'],
                    'followers' => [['gid' => '11'], ['gid' => '12'], ['gid' => '13']],
                    'tags' => [['name' => 'Design']],
                    'dependencies' => [['gid' => '1003']],
                    'memberships' => [['project' => ['gid' => '100'], 'section' => ['gid' => '111']]],
                    'custom_fields' => [
                        ['gid' => '5001', 'resource_subtype' => 'enum', 'enum_value' => ['name' => 'High']],
                        ['gid' => '5002', 'resource_subtype' => 'number', 'number_value' => 5],
                    ],
                ]),
                $this->task('1004', 'Fertig', [
                    'completed' => true, 'completed_at' => '2026-10-03T12:00:00.000Z',
                    'memberships' => [['project' => ['gid' => '100'], 'section' => ['gid' => '111']]],
                ]),
                $this->task('1003', 'Umsetzung', [
                    'assignee' => $this->person('12', 'Ben', 'ben@example.com'),
                    'memberships' => [['project' => ['gid' => '100'], 'section' => ['gid' => '112']], ['project' => ['gid' => '200'], 'section' => ['gid' => '210']]],
                ]),
                // A subtask that is a member of the project too is listed here as well, with its parent
                $this->task('1002', 'Skizzen', ['parent' => ['gid' => '1001'], 'memberships' => [['project' => ['gid' => '100'], 'section' => ['gid' => '111']]]]),
            ],
            'tasks/1001/subtasks' => [
                $this->task('1002', 'Skizzen', ['completed' => true, 'completed_at' => '2026-10-01T10:00:00.000Z', 'assignee' => $this->person('12', 'Ben', 'ben@example.com')]),
            ],
            'tasks/1001/stories' => [
                ['gid' => '9001', 'resource_subtype' => 'assigned', 'text' => 'Anna assigned to you'],
                ['gid' => '9002', 'resource_subtype' => 'comment_added', 'created_at' => '2026-09-05T10:00:00.000Z', 'created_by' => ['gid' => '11', 'name' => 'Anna'], 'text' => 'Sieht gut aus', 'html_text' => '<body>Sieht <strong>gut</strong> aus, siehe <a href="https://app.asana.com/0/100/1003">Umsetzung</a></body>'],
                ['gid' => '9003', 'resource_subtype' => 'comment_added', 'created_at' => '2026-09-06T11:00:00.000Z', 'created_by' => ['gid' => '13', 'name' => 'Cara'], 'text' => 'Ich bin dabei', 'html_text' => '<body>Ich bin dabei</body>'],
            ],
            'attachments?parent=1001' => [
                ['gid' => '7001', 'name' => 'plan.pdf', 'host' => 'asana', 'size' => 9, 'created_at' => '2026-09-03T10:00:00.000Z', 'permanent_url' => 'https://app.asana.com/app/asana/-/get_asset?asset_id=7001'],
                ['gid' => '7002', 'name' => 'Entwurf in Dropbox', 'host' => 'dropbox', 'permanent_url' => 'https://dropbox.test/entwurf'],
            ],
            'attachments/7001' => ['gid' => '7001', 'download_url' => 'https://download.example.com/plan.pdf'],
            'download.example.com/plan.pdf' => '%PDF-fake',

            'projects/200' => ['gid' => '200', 'name' => 'Marketing', 'notes' => '', 'color' => null, 'archived' => false, 'owner' => ['gid' => '12'], 'workspace' => ['gid' => '900'], 'members' => [$this->person('12', 'Ben', 'ben@example.com')]],
            'projects/200/sections' => [['gid' => '210', 'name' => 'Untitled section']],
            'projects/200/custom_field_settings' => [],
            'projects/200/tasks' => [
                $this->task('1003', 'Umsetzung', ['assignee' => $this->person('12', 'Ben', 'ben@example.com'), 'memberships' => [['project' => ['gid' => '200']]]]),
                $this->task('2001', 'Newsletter', ['resource_subtype' => 'milestone', 'assignee' => $this->person('12', 'Ben', 'ben@example.com'), 'tags' => [['name' => 'design']]]),
            ],
        ];
    }

    /**
     * Answers the requests to Asana from the routes (which a test may change between two runs).
     */
    private function serveAsana(): void
    {
        Http::fake(function (Request $request) {
            $parts = parse_url($request->url());
            parse_str($parts['query'] ?? '', $query);
            $path = str_starts_with($request->url(), AsanaClient::BASE_URL) ? substr($parts['path'], strlen('/api/1.0/')) : $parts['host'].$parts['path'];
            $key = $path.(isset($query['workspace']) ? '?workspace='.$query['workspace'] : '').(isset($query['parent']) ? '?parent='.$query['parent'] : '');

            if (! array_key_exists($key, $this->routes)) {
                // Lists nobody filled are empty; anything else Asana does not know
                return preg_match('~^(attachments\?parent=|tasks/\d+/(stories|subtasks))~', $key) === 1
                    ? Http::response(['data' => [], 'next_page' => null])
                    : Http::response(['errors' => [['message' => "no answer for {$key}"]]], 404);
            }

            $answer = $this->routes[$key];

            if (is_array($answer) && isset($answer['__error'])) {
                return Http::response(['errors' => [['message' => $answer['__error']['message']]]], $answer['__error']['status']);
            }

            return is_string($answer) ? Http::response($answer) : Http::response(['data' => $answer, 'next_page' => null]);
        });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function asana(array $arguments): PendingCommand
    {
        $this->serveAsana();

        return $this->artisan('asana:import', $arguments);
    }

    /**
     * Both projects, with the people from Asana who have no account left out.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function import(array $arguments = []): PendingCommand
    {
        return $this->asana($arguments + ['projects' => ['100', '200'], '--token' => 'secret-token', '--ignore-missing-users' => true]);
    }

    public function test_a_dry_run_reads_from_asana_and_changes_nothing(): void
    {
        $this->import(['--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was changed')
            ->expectsOutputToContain('Cara')
            ->assertSuccessful();

        $this->assertSame(0, Project::count());
        $this->assertSame(0, DB::table('asana_imports')->count());
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_people_without_an_account_stop_the_import_unless_told_otherwise(): void
    {
        $this->asana(['projects' => ['100'], '--token' => 'secret-token'])
            ->expectsOutputToContain('have no account here')
            ->assertFailed();

        $this->assertSame(0, Project::count());
    }

    public function test_projects_tasks_people_and_fields_are_taken_over(): void
    {
        $this->import()->assertSuccessful();

        $website = Project::where('name', 'Website')->firstOrFail();
        $this->assertSame('Der neue Auftritt', $website->description);
        $this->assertSame('#22c55e', $website->color);
        $this->assertEqualsCanonicalizing([$this->anna->id, $this->ben->id, $this->admin->id], $website->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all());

        // Sections become statuses between the first standard one and the done one
        $this->assertSame([__('Open'), 'Backlog', 'Doing', __('Done')], $website->statuses()->pluck('name')->all());

        $concept = Task::where('title', 'Konzept')->firstOrFail();
        $this->assertSame($website->id, $concept->project_id);
        $this->assertSame('Backlog', $concept->status->name);
        $this->assertSame($this->anna->id, $concept->assignee_id);
        $this->assertSame($this->ben->id, $concept->creator_id);
        $this->assertSame('2026-11-05', $concept->due_date->toDateString());
        $this->assertSame('2026-09-01', $concept->created_at->toDateString());
        $this->assertSame(['Design'], $concept->tags()->pluck('name')->all());

        // Followers become collaborators when they are members here; Cara has no account
        $this->assertSame([$this->ben->id], $concept->collaborators()->pluck('users.id')->map(fn ($id) => (int) $id)->all());

        $subtask = Task::where('title', 'Skizzen')->firstOrFail();
        $this->assertSame(1, Task::where('title', 'Skizzen')->count());
        $this->assertSame($concept->id, $subtask->parent_id);
        $this->assertTrue($subtask->isDone());
        $this->assertSame('2026-10-01', $subtask->completed_at->toDateString());
        $this->assertSame($this->ben->id, $subtask->assignee_id);

        $this->assertSame(__('Done'), Task::where('title', 'Fertig')->firstOrFail()->status->name);
        $this->assertSame('Doing', Task::where('title', 'Umsetzung')->firstOrFail()->status->name);

        // The existing priority field is used by its name; new fields and options are created
        $priority = $website->customFields()->where('name', __('Priority'))->firstOrFail();
        $this->assertSame('High', $concept->fieldValues()->where('custom_field_id', $priority->id)->firstOrFail()->option->name);
        $this->assertTrue($priority->options()->where('name', 'Low')->exists());
        $this->assertFalse($priority->options()->where('name', 'Hidden')->exists());
        $effort = $website->customFields()->where('name', 'Aufwand')->firstOrFail();
        $this->assertSame(CustomFieldType::Number, $effort->type);
        $this->assertSame('5', $concept->fieldValues()->where('custom_field_id', $effort->id)->firstOrFail()->value);
        $this->assertFalse($website->customFields()->where('name', 'Formel')->exists());

        Notification::assertNothingSent();
    }

    public function test_a_task_in_several_projects_is_created_in_the_first_one_only(): void
    {
        $this->import()->assertSuccessful();

        $marketing = Project::where('name', 'Marketing')->firstOrFail();

        $this->assertSame(1, Task::where('title', 'Umsetzung')->count());
        $this->assertSame(Project::where('name', 'Website')->value('id'), Task::where('title', 'Umsetzung')->value('project_id'));
        $this->assertSame(['Newsletter'], $marketing->tasks()->pluck('title')->all());

        // A project without sections of its own keeps the standard statuses; a milestone gets a tag
        $this->assertSame([__('Open'), __('In progress'), __('Done')], $marketing->statuses()->pluck('name')->all());
        $this->assertEqualsCanonicalizing([__('Milestone'), 'design'], $marketing->tags()->pluck('name')->all());
    }

    public function test_dependencies_and_links_between_tasks_point_to_the_imported_tasks(): void
    {
        $this->import()->assertSuccessful();

        $concept = Task::where('title', 'Konzept')->firstOrFail();
        $implementation = Task::where('title', 'Umsetzung')->firstOrFail();

        $this->assertSame([$implementation->id], $concept->blockers()->pluck('tasks.id')->all());
        $this->assertStringContainsString("@[Umsetzung](task:{$implementation->id})", (string) $concept->description);
        $this->assertStringContainsString("**Plan** für @[Ben](user:{$this->ben->id}),", (string) $concept->description);
    }

    public function test_comments_keep_author_time_and_text_and_unknown_authors_are_named(): void
    {
        $this->import()->assertSuccessful();

        $concept = Task::where('title', 'Konzept')->firstOrFail();
        $implementation = Task::where('title', 'Umsetzung')->firstOrFail();
        $comments = $concept->comments()->orderBy('id')->get();

        $this->assertCount(2, $comments);
        $this->assertSame($this->anna->id, $comments[0]->user_id);
        $this->assertSame("Sieht **gut** aus, siehe @[Umsetzung](task:{$implementation->id})", $comments[0]->body);
        // 10:00 UTC, shown in the application's time zone
        $this->assertSame(Carbon::parse('2026-09-05T10:00:00Z')->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), $comments[0]->created_at->format('Y-m-d H:i'));
        $this->assertSame($this->admin->id, $comments[1]->user_id);
        $this->assertStringContainsString('**'.__('From :name in Asana', ['name' => 'Cara']).'**', $comments[1]->body);
        $this->assertStringContainsString('Ich bin dabei', $comments[1]->body);
        Notification::assertNothingSent();
    }

    public function test_files_are_downloaded_without_the_token_and_other_attachments_become_links(): void
    {
        $this->import()->assertSuccessful();

        $concept = Task::where('title', 'Konzept')->firstOrFail();
        $attachment = Attachment::where('task_id', $concept->id)->firstOrFail();

        $this->assertSame('plan.pdf', $attachment->name);
        $this->assertSame(9, $attachment->size);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('2026-09-03', $attachment->created_at->toDateString());
        Storage::disk()->assertExists($attachment->path);
        $this->assertSame('%PDF-fake', Storage::disk()->get($attachment->path));
        $this->assertStringContainsString('[Entwurf in Dropbox](https://dropbox.test/entwurf)', (string) $concept->description);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'download.example.com') && ! $request->hasHeader('Authorization'));
    }

    public function test_a_second_run_only_adds_what_is_new(): void
    {
        $this->import()->assertSuccessful();
        $tasks = Task::count();
        $rows = DB::table('asana_imports')->count();

        $this->routes['tasks/1001/stories'][] = ['gid' => '9004', 'resource_subtype' => 'comment_added', 'created_at' => '2026-10-09T10:00:00.000Z', 'created_by' => ['gid' => '11', 'name' => 'Anna'], 'text' => 'Noch etwas', 'html_text' => '<body>Noch etwas</body>'];
        $this->routes['projects/100/tasks'][] = $this->task('1005', 'Nachzügler', ['memberships' => [['project' => ['gid' => '100'], 'section' => ['gid' => '112']]]]);

        $this->import()->assertSuccessful();

        $this->assertSame($tasks + 1, Task::count());
        $this->assertSame($rows + 2, DB::table('asana_imports')->count());
        $this->assertSame(3, Comment::count());
        $this->assertSame(1, Attachment::count());
        $this->assertSame(1, Project::where('name', 'Website')->count());
        $this->assertSame('Doing', Task::where('title', 'Nachzügler')->firstOrFail()->status->name);
    }

    public function test_sections_can_become_headings_instead_of_statuses(): void
    {
        $this->import(['projects' => ['100'], '--sections' => 'headings'])->assertSuccessful();

        $website = Project::where('name', 'Website')->firstOrFail();

        $this->assertSame([__('Open'), __('In progress'), __('Done')], $website->statuses()->pluck('name')->all());
        $this->assertSame(['Backlog', 'Konzept', 'Fertig', 'Doing', 'Umsetzung'], $website->tasks()->topLevel()->orderBy('position')->pluck('title')->all());
        $this->assertSame(['Backlog', 'Doing'], $website->tasks()->where('is_section', true)->orderBy('position')->pluck('title')->all());
        $this->assertSame(__('Open'), Task::where('title', 'Konzept')->firstOrFail()->status->name);
    }

    public function test_people_can_be_created_on_the_way_without_sending_anything(): void
    {
        $this->asana(['projects' => ['100'], '--token' => 'secret-token', '--create-users' => true])->assertSuccessful();

        $cara = User::where('email', 'cara@example.com')->firstOrFail();
        $this->assertSame('Cara', $cara->name);
        $this->assertTrue(Project::where('name', 'Website')->firstOrFail()->members()->whereKey($cara->id)->exists());
        Notification::assertNothingSent();
    }

    public function test_a_project_can_be_imported_into_an_existing_one(): void
    {
        $existing = Project::factory()->create(['name' => 'Schon da']);

        $this->asana(['projects' => ["200={$existing->id}"], '--token' => 'secret-token', '--ignore-missing-users' => true])->assertSuccessful();

        $this->assertSame(1, Project::count());
        $this->assertSame(['Umsetzung', 'Newsletter'], $existing->tasks()->orderBy('id')->pluck('title')->all());
    }

    public function test_a_plan_without_custom_fields_imports_the_project_without_them(): void
    {
        $this->routes['projects/100/custom_field_settings'] = ['__error' => ['status' => 402, 'message' => 'Custom Field Settings are not available for free users.']];

        $this->import(['projects' => ['100']])->expectsOutputToContain('not available with this Asana plan')->assertSuccessful();

        $website = Project::where('name', 'Website')->firstOrFail();
        $this->assertFalse($website->customFields()->where('name', 'Aufwand')->exists());
        $this->assertSame(['Konzept', 'Skizzen', 'Fertig', 'Umsetzung'], $website->tasks()->orderBy('id')->pluck('title')->all());
    }

    public function test_other_errors_while_reading_stop_the_import(): void
    {
        $this->routes['projects/100/custom_field_settings'] = ['__error' => ['status' => 500, 'message' => 'Server Error']];
        Sleep::fake();

        $this->import(['projects' => ['100']])->expectsOutputToContain('Server Error')->assertFailed();

        $this->assertSame(0, Project::count());
    }

    public function test_the_projects_of_the_token_can_be_listed(): void
    {
        $this->asana(['--list' => true, '--token' => 'secret-token'])
            ->expectsOutputToContain('Firma')
            ->expectsOutputToContain('Marketing')
            ->assertSuccessful();
    }

    public function test_a_missing_token_or_a_wrong_project_id_is_reported(): void
    {
        $this->artisan('asana:import', ['projects' => ['100'], '--no-interaction' => true])->expectsOutputToContain('No token')->assertFailed();
        $this->artisan('asana:import', ['projects' => ['abc'], '--token' => 'x'])->expectsOutputToContain('not an Asana project id')->assertFailed();
    }

    public function test_the_client_waits_and_tries_again_when_asana_asks_for_a_pause(): void
    {
        Sleep::fake();
        Http::fake(['app.asana.com/*' => Http::sequence()->push(['errors' => [['message' => 'Too many']]], 429, ['Retry-After' => '7'])->push(['data' => ['gid' => '1']])]);

        $this->assertSame(['gid' => '1'], (new AsanaClient('t'))->get('projects/1'));
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    }

    public function test_the_client_follows_the_pages_of_a_list(): void
    {
        Http::fake(['app.asana.com/*' => Http::sequence()
            ->push(['data' => [['gid' => '1'], ['gid' => '2']], 'next_page' => ['offset' => 'abc']])
            ->push(['data' => [['gid' => '3']], 'next_page' => null])]);

        $this->assertSame(['1', '2', '3'], array_column(iterator_to_array((new AsanaClient('t'))->paginate('tasks')), 'gid'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'offset=abc') && str_contains($request->url(), 'limit=100'));
    }

    public function test_the_client_explains_a_refused_token(): void
    {
        Http::fake(['app.asana.com/*' => Http::response(['errors' => [['message' => 'Not Authorized']]], 401)]);

        $this->expectException(AsanaException::class);
        $this->expectExceptionMessage('does not accept the token');

        (new AsanaClient('wrong'))->get('users/me');
    }

    public function test_only_https_downloads_are_fetched(): void
    {
        Http::fake();

        $this->assertNull((new AsanaClient('t'))->download('http://download.example.com/a'));
        Http::assertNothingSent();
    }
}
