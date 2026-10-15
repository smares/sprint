<?php

namespace Tests\Feature;

use App\Enums\CustomFieldType;
use App\Enums\ProjectRole;
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
use ReflectionMethod;
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
                    'assignee' => $this->person('13', 'Cara', 'cara@example.com'),
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
            'attachments/7001' => ['gid' => '7001', 'download_url' => 'https://download.example.com/plan.pdf?e=1791992839&t=signature'],
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

    public function test_an_open_subtask_follows_the_status_of_its_task_unless_that_is_done(): void
    {
        foreach ($this->routes['projects/100/tasks'] as &$raw) {
            $raw['num_subtasks'] = $raw['gid'] === '1004' ? 1 : $raw['num_subtasks'];
        }

        unset($raw);
        $this->routes['tasks/1001/subtasks'][] = $this->task('1006', 'Detail');
        $this->routes['tasks/1004/subtasks'] = [$this->task('1007', 'Rest')];

        $this->import(['projects' => ['100']])->assertSuccessful();

        $backlog = Task::where('title', 'Konzept')->firstOrFail()->status;
        $this->assertSame($backlog->id, Task::where('title', 'Detail')->firstOrFail()->status_id);
        $this->assertTrue(Task::where('title', 'Skizzen')->firstOrFail()->status->is_done);

        // "Fertig" is done, so its open subtask is not counted as done with it
        $this->assertTrue(Task::where('title', 'Fertig')->firstOrFail()->status->is_done);
        $this->assertFalse(Task::where('title', 'Rest')->firstOrFail()->status->is_done);
    }

    public function test_a_comment_without_text_is_reported_and_not_taken_over(): void
    {
        $this->routes['tasks/1001/stories'][] = ['gid' => '9005', 'resource_subtype' => 'comment_added', 'created_at' => '2026-10-09T10:00:00.000Z', 'created_by' => ['gid' => '11', 'name' => 'Anna'], 'text' => '', 'html_text' => '<body></body>'];
        $this->import(['--dry-run' => true])
            ->expectsOutputToContain('the comment 9005 has no text')
            ->expectsOutputToContain('Comments without text (not taken over)')
            ->assertSuccessful();

        $this->import()->expectsOutputToContain('the comment 9005 has no text')->assertSuccessful();

        $this->assertNull(DB::table('asana_imports')->where('kind', 'comment')->where('gid', '9005')->first());
        $this->assertSame(2, DB::table('comments')->count());
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

        // The signature in the address is kept, the token is not sent along
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'download.example.com/plan.pdf?e=1791992839&t=signature') && ! $request->hasHeader('Authorization'));
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

    public function test_an_assignment_without_an_account_is_noted_in_the_description(): void
    {
        $this->import()->assertSuccessful();

        $finished = Task::where('title', 'Fertig')->firstOrFail();

        $this->assertNull($finished->assignee_id);
        $this->assertStringContainsString('**'.__('Assignee in Asana').':** Cara', (string) $finished->description);
        $this->assertStringNotContainsString(__('Assignee in Asana'), (string) Task::where('title', 'Konzept')->firstOrFail()->description);
    }

    public function test_people_can_be_created_deactivated_and_without_sending_anything(): void
    {
        $this->asana(['projects' => ['100'], '--token' => 'secret-token', '--create-users' => true])->assertSuccessful();

        $cara = User::where('email', 'cara@example.com')->firstOrFail();
        $this->assertSame('Cara', $cara->name);
        $this->assertFalse($cara->isActive());
        $this->assertTrue(Project::where('name', 'Website')->firstOrFail()->members()->whereKey($cara->id)->exists());

        // The assignment stays, and an administrator can activate the person later
        $finished = Task::where('title', 'Fertig')->firstOrFail();
        $this->assertSame($cara->id, $finished->assignee_id);
        $this->assertStringNotContainsString(__('Assignee in Asana'), (string) $finished->description);
        $cara->reactivate();
        $this->assertTrue($cara->fresh()->isActive());
        Notification::assertNothingSent();

        // A second run finds the deactivated account again instead of creating it twice
        $this->asana(['projects' => ['100'], '--token' => 'secret-token', '--create-users' => true])->assertSuccessful();
        $this->assertSame(1, User::where('email', 'cara@example.com')->count());
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

    public function test_without_project_ids_the_projects_can_be_chosen_from_a_list(): void
    {
        $this->asana(['--token' => 'secret-token', '--dry-run' => true, '--ignore-missing-users' => true])
            ->expectsChoice('Which projects are to be imported?', ['200'], ['100' => 'Website', '200' => 'Marketing'])
            ->expectsOutputToContain('Dry run: nothing was changed')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'projects/200'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'projects/100'));
    }

    public function test_the_token_can_come_from_the_environment_of_the_process(): void
    {
        putenv('ASANA_TOKEN=token-from-the-environment');

        try {
            $this->serveAsana();
            $this->artisan('asana:import', ['--list' => true, '--no-interaction' => true])->assertSuccessful();
        } finally {
            putenv('ASANA_TOKEN');
        }

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token-from-the-environment'));
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

        $pauses = [];
        $client = new AsanaClient('t');
        $client->whenPausing(function (int $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });

        $this->assertSame(['gid' => '1'], $client->get('projects/1'));
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
        $this->assertSame([7], $pauses);
    }

    public function test_the_progress_is_shown_while_reading_and_while_writing(): void
    {
        $this->import(['--ansi' => true])
            ->expectsOutputToContain('tasks read')
            ->expectsOutputToContain('100%')
            ->assertSuccessful();
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

        $this->assertFalse((new AsanaClient('t'))->download('http://download.example.com/a', sys_get_temp_dir().'/asana-never'));
        Http::assertNothingSent();
    }

    public function test_texts_over_the_limit_are_shortened_with_a_warning(): void
    {
        $this->routes['projects/100/tasks'][0]['notes'] = str_repeat('ä', Task::MAX_DESCRIPTION_LENGTH + 500);
        $this->routes['projects/100/tasks'][0]['html_notes'] = null;
        $this->routes['tasks/1001/stories'][] = ['gid' => '9009', 'resource_subtype' => 'comment_added', 'created_at' => '2026-10-09T10:00:00.000Z', 'created_by' => ['gid' => '11', 'name' => 'Anna'], 'text' => str_repeat('b', Comment::MAX_BODY_LENGTH + 10), 'html_text' => ''];

        $this->import()->expectsOutputToContain('was shortened')->assertSuccessful();

        $concept = Task::where('title', 'Konzept')->firstOrFail();
        $this->assertSame(Task::MAX_DESCRIPTION_LENGTH, mb_strlen((string) $concept->description));
        $this->assertSame(Comment::MAX_BODY_LENGTH, $concept->comments()->get()->max(fn (Comment $comment) => mb_strlen($comment->body)));
    }

    public function test_files_that_could_not_be_fetched_make_the_run_fail_and_a_second_run_fetches_them(): void
    {
        $this->routes['attachments/7001'] = ['__error' => ['status' => 403, 'message' => 'forbidden']];

        $this->import()->expectsOutputToContain('1 file(s) were not taken over')->assertFailed();

        $concept = Task::where('title', 'Konzept')->firstOrFail();
        $this->assertSame(0, Attachment::where('task_id', $concept->id)->count());

        $this->routes['attachments/7001'] = ['gid' => '7001', 'download_url' => 'https://download.example.com/plan.pdf?e=1791992839&t=signature'];

        $this->import()->assertSuccessful();

        $this->assertSame(1, Attachment::where('task_id', $concept->id)->count());
        $this->assertSame(2, Project::count());
    }

    public function test_a_project_named_twice_is_imported_once(): void
    {
        $this->import(['projects' => ['100', '100']])->assertSuccessful();

        $this->assertSame(1, Project::where('name', 'Website')->count());
    }

    public function test_an_answer_that_is_not_json_does_not_pass_for_an_empty_list(): void
    {
        Http::fake(['*' => Http::response('<html>Please log in to the Wi-Fi</html>', 200)]);

        $this->expectException(AsanaException::class);
        $this->expectExceptionMessage('not JSON');

        (new AsanaClient('t'))->get('workspaces');
    }

    public function test_a_person_with_another_address_is_not_matched_by_name(): void
    {
        // Another Cara: the address from Asana is not hers, so the Cara of Asana has no account here
        User::factory()->create(['name' => 'Cara', 'email' => 'cara@firma.example']);

        $this->asana(['projects' => ['100'], '--token' => 'secret-token'])
            ->expectsOutputToContain('have no account here')
            ->assertFailed();

        $this->assertSame(0, Project::count());
    }

    public function test_a_person_without_any_address_is_matched_by_a_unique_name(): void
    {
        $dora = User::factory()->create(['name' => 'Dora', 'email' => 'dora@example.com']);
        $this->routes['projects/100']['members'][] = $this->person('14', 'Dora', null);
        $this->routes['users?workspace=900'][] = $this->person('14', 'Dora', null);

        $this->import(['projects' => ['100']])->assertSuccessful();

        $this->assertTrue(Project::where('name', 'Website')->firstOrFail()->members()->whereKey($dora->id)->exists());
    }

    public function test_the_stored_file_gets_the_extension_of_its_type_and_not_of_its_name(): void
    {
        $this->routes['attachments?parent=1001'][0]['name'] = 'evil.php';

        $this->import()->assertSuccessful();

        $attachment = Attachment::where('name', 'evil.php')->firstOrFail();
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertStringEndsWith('.pdf', $attachment->path);
        $this->assertStringNotContainsString('php', $attachment->path);
    }

    public function test_a_failed_download_does_not_show_its_address_with_the_signature(): void
    {
        Http::fake(['download.example.com/*' => Http::failedConnection()]);

        $client = new AsanaClient('t');
        $client->whenPausing(fn () => null);
        Sleep::fake();

        try {
            $client->download('https://download.example.com/plan.pdf?X-Amz-Signature=geheim', sys_get_temp_dir().'/asana-never');
            $this->fail('The download should have failed.');
        } catch (AsanaException $exception) {
            $this->assertStringNotContainsString('geheim', $exception->getMessage());
            $this->assertSame('the download failed', $exception->getMessage());
        }
    }

    public function test_redirects_of_the_client_stay_on_https(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        (new AsanaClient('t'))->get('workspaces');

        $options = (new ReflectionMethod(AsanaClient::class, 'request'))->invoke(new AsanaClient('t'), true)->getOptions();
        $this->assertSame(['https'], $options['allow_redirects']['protocols']);
    }

    public function test_the_importer_keeps_their_role_in_an_existing_project_and_gets_one_in_a_new_one(): void
    {
        $importer = User::factory()->create(['email' => 'importer@example.com']);
        $existing = Project::factory()->create(['name' => 'Schon da']);
        $existing->setRole($importer, ProjectRole::Viewer);

        $this->asana(['projects' => ["200={$existing->id}"], '--token' => 'secret-token', '--as' => 'importer@example.com', '--ignore-missing-users' => true])->assertSuccessful();

        $this->assertSame(ProjectRole::Viewer, $existing->roleFor($importer->fresh()));

        $this->asana(['projects' => ['100'], '--token' => 'secret-token', '--as' => 'importer@example.com', '--ignore-missing-users' => true])->assertSuccessful();

        $this->assertSame(ProjectRole::Admin, Project::where('name', 'Website')->firstOrFail()->roleFor($importer->fresh()));
    }

    public function test_whoever_was_signed_in_is_signed_in_again_after_the_import(): void
    {
        $this->actingAs($this->ben);

        $this->import()->assertSuccessful();

        $this->assertSame($this->ben->id, auth()->id());
    }

    public function test_nobody_is_signed_in_after_an_import_that_started_without_a_user(): void
    {
        $this->import()->assertSuccessful();

        $this->assertGuest();
    }

    public function test_a_refused_download_says_why_without_its_address(): void
    {
        Http::fake(['download.example.com/*' => Http::response('expired', 403)]);

        try {
            (new AsanaClient('t'))->download('https://download.example.com/plan.pdf?X-Amz-Signature=geheim', sys_get_temp_dir().'/asana-never');
            $this->fail('The download should have failed.');
        } catch (AsanaException $exception) {
            $this->assertSame('the download answered with HTTP 403', $exception->getMessage());
        }
    }

    public function test_options_are_checked_before_anything_is_read_from_asana(): void
    {
        $this->serveAsana();

        $this->artisan('asana:import', ['projects' => ['100'], '--token' => 'secret-token', '--sections' => 'nonsense'])
            ->expectsOutputToContain('--sections is status or headings.')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
