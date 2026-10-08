<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Mcp\Servers\SprintServer;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\CreateTask;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\UpdateTask;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'ich@example.com']);
        $this->project = Project::factory()->create(['name' => 'Website']);
        $this->project->setRole($this->user, ProjectRole::Editor);
    }

    private function tool(string $tool, array $arguments = [], ?User $as = null): \Laravel\Mcp\Server\Testing\TestResponse
    {
        return SprintServer::actingAs($as ?? $this->user)->tool($tool, $arguments);
    }

    /**
     * Calls a tool over HTTP with a real token.
     *
     * @return TestResponse<Response>
     */
    private function http(string $token, string $tool, array $arguments = [])
    {
        return $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ], ['Accept' => 'application/json, text/event-stream']);
    }

    public function test_the_server_needs_a_valid_token(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $this->withToken('nonsense')->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_a_session_cookie_is_not_enough(): void
    {
        $this->actingAs($this->user)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_a_token_lists_the_tools_and_works(): void
    {
        $token = $this->user->createToken('Agent', ['read', 'write'])->plainTextToken;

        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->assertSee(['list-projects', 'list-tasks', 'get-task', 'create-task', 'update-task', 'add-comment']);

        $this->http($token, 'create-task', ['project_id' => $this->project->id, 'title' => 'Per HTTP'])->assertOk()->assertSee('Per HTTP');
        $this->assertDatabaseHas('tasks', ['title' => 'Per HTTP', 'creator_id' => $this->user->id]);
    }

    public function test_expired_and_revoked_tokens_and_deactivated_people_are_rejected(): void
    {
        $expired = $this->user->createToken('Alt', ['*'], now()->subMinute())->plainTextToken;
        $this->http($expired, 'list-projects')->assertUnauthorized();

        $revoked = $this->user->createToken('Weg', ['*']);
        $revoked->accessToken->delete();
        $this->http($revoked->plainTextToken, 'list-projects')->assertUnauthorized();

        $live = $this->user->createToken('Neu', ['*'])->plainTextToken;
        $this->user->deactivate();
        $this->assertSame(0, $this->user->tokens()->count());
        $this->http($live, 'list-projects')->assertUnauthorized();
    }

    public function test_read_only_tokens_cannot_write(): void
    {
        $token = $this->user->createToken('Lesen', ['read'])->plainTextToken;
        $task = Task::factory()->for($this->project)->create();

        $this->http($token, 'list-tasks')->assertOk()->assertSee($task->title);
        $this->http($token, 'create-task', ['project_id' => $this->project->id, 'title' => 'Nein'])->assertSee('read-only');
        $this->http($token, 'update-task', ['task_id' => $task->id, 'title' => 'Nein'])->assertSee('read-only');
        $this->http($token, 'add-comment', ['task_id' => $task->id, 'body' => 'Nein'])->assertSee('read-only');

        $this->assertSame(1, Task::count());
        $this->assertSame(0, Comment::count());
    }

    public function test_projects_show_what_an_agent_needs_to_know(): void
    {
        Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        $archived = Project::factory()->create(['archived_at' => now()]);
        $archived->setRole($this->user, ProjectRole::Editor);
        Project::factory()->create(['name' => 'Fremd']);

        $this->tool(ListProjects::class)->assertOk()
            ->assertSee(['Website', 'Bug', 'Offen', 'Erledigt', 'Priorität', 'Hoch', 'ich@example.com', 'editor'])
            ->assertDontSee('Fremd')->assertDontSee($archived->name);

        $this->tool(ListProjects::class, ['include_archived' => true])->assertSee($archived->name);
    }

    public function test_tasks_can_be_listed_filtered_and_searched(): void
    {
        $other = User::factory()->create(['email' => 'anna@example.com']);
        $this->project->setRole($other, ProjectRole::Editor);
        $mine = Task::factory()->for($this->project)->create(['title' => 'Meine Rechnung', 'assignee_id' => $this->user->id]);
        Task::factory()->for($this->project)->create(['title' => 'Annas Entwurf', 'assignee_id' => $other->id]);
        Task::factory()->for($this->project)->done()->create(['title' => 'Fertiges']);
        Task::factory()->for($this->project)->create(['title' => 'Unteraufgabe', 'parent_id' => $mine->id]);
        Task::factory()->for($this->project)->create(['title' => 'Überfällig', 'due_date' => today()->subDay()]);
        Task::factory()->for(Project::factory()->create())->create(['title' => 'Geheimes']);

        $this->tool(ListTasks::class)->assertSee(['Meine Rechnung', 'Annas Entwurf'])->assertDontSee(['Fertiges', 'Unteraufgabe', 'Geheimes']);
        $this->tool(ListTasks::class, ['state' => 'done'])->assertSee('Fertiges')->assertDontSee('Annas Entwurf');
        $this->tool(ListTasks::class, ['assignee' => 'me'])->assertSee('Meine Rechnung')->assertDontSee('Annas Entwurf');
        $this->tool(ListTasks::class, ['assignee' => 'anna@example.com'])->assertSee('Annas Entwurf')->assertDontSee('Meine Rechnung');
        $this->tool(ListTasks::class, ['overdue' => true])->assertSee('Überfällig')->assertDontSee('Meine Rechnung');
        $this->tool(ListTasks::class, ['include_subtasks' => true])->assertSee('Unteraufgabe');
        $this->tool(ListTasks::class, ['query' => 'rechnung'])->assertSee('Meine Rechnung')->assertDontSee('Annas Entwurf');
        $this->tool(ListTasks::class, ['query' => 'geheimes'])->assertDontSee('Geheimes');
    }

    public function test_listing_is_paged(): void
    {
        Task::factory()->for($this->project)->count(55)->create();

        $this->tool(ListTasks::class)->assertSee('"has_more":true');
        $this->tool(ListTasks::class, ['offset' => 50])->assertSee('"has_more":false');
    }

    public function test_listing_a_foreign_project_fails(): void
    {
        $foreign = Project::factory()->create();

        $this->tool(ListTasks::class, ['project_id' => $foreign->id])->assertHasErrors(["Project {$foreign->id} not found."]);
    }

    public function test_get_task_shows_details(): void
    {
        $task = Task::factory()->for($this->project)->create(['title' => 'Detail', 'description' => 'Langer Text']);
        Task::factory()->for($this->project)->create(['title' => 'Kind', 'parent_id' => $task->id]);
        Comment::factory()->create(['task_id' => $task->id, 'user_id' => $this->user->id, 'body' => 'Ein Kommentar']);

        $this->tool(GetTask::class, ['task_id' => $task->id])->assertOk()->assertSee(['Detail', 'Langer Text', 'Kind', 'Ein Kommentar']);
    }

    public function test_get_task_hides_foreign_tasks_and_headings(): void
    {
        $foreign = Task::factory()->for(Project::factory()->create())->create();
        $heading = Task::factory()->for($this->project)->create(['is_section' => true]);

        $this->tool(GetTask::class, ['task_id' => $foreign->id])->assertHasErrors(["Task {$foreign->id} not found."]);
        $this->tool(GetTask::class, ['task_id' => $heading->id])->assertHasErrors(["Task {$heading->id} not found."]);
    }

    public function test_a_task_is_created_with_everything_resolved_by_name(): void
    {
        $anna = User::factory()->create(['email' => 'anna@example.com']);
        $this->project->setRole($anna, ProjectRole::Editor);
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Bug']);

        $this->tool(CreateTask::class, [
            'project_id' => $this->project->id, 'title' => '  Login kaputt ', 'description' => 'Siehe Log',
            'status' => 'in arbeit', 'assignee' => 'anna@example.com', 'due_date' => '2026-12-01', 'start_date' => '2026-11-20',
            'tags' => ['bug'], 'collaborators' => ['ich@example.com'], 'fields' => ['priorität' => 'hoch'],
        ])->assertOk()->assertSee(['Login kaputt', 'In Arbeit', 'anna@example.com', '2026-12-01', 'Bug', 'Hoch']);

        $task = Task::where('title', 'Login kaputt')->firstOrFail();
        $this->assertSame($this->user->id, $task->creator_id);
        $this->assertSame($anna->id, $task->assignee_id);
        $this->assertSame('In Arbeit', $task->status->name);
        $this->assertSame([$tag->id], $task->tags()->pluck('tags.id')->all());
        $this->assertSame([$this->user->id], $task->collaborators()->pluck('users.id')->all());
        $this->assertContains('hat die Aufgabe angelegt', $task->activities()->get()->map->sentence()->all());
    }

    public function test_a_minimal_task_gets_defaults_and_goes_to_the_end(): void
    {
        Task::factory()->for($this->project)->create(['position' => 4]);

        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'Neu'])->assertOk();

        $task = Task::where('title', 'Neu')->firstOrFail();
        $this->assertSame($this->project->defaultStatus()->id, $task->status_id);
        $this->assertSame(5, $task->position);
        $this->assertNull($task->assignee_id);
    }

    public function test_a_subtask_belongs_to_a_parent_of_the_same_project(): void
    {
        $parent = Task::factory()->for($this->project)->create();
        Task::factory()->for($this->project)->create(['parent_id' => $parent->id, 'position' => 2]);
        $foreign = Task::factory()->for(Project::factory()->create())->create();

        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'Kind', 'parent_id' => $parent->id])->assertOk();
        $this->assertSame(3, Task::where('title', 'Kind')->firstOrFail()->position);
        $this->assertSame($parent->id, Task::where('title', 'Kind')->firstOrFail()->parent_id);

        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'Fremd', 'parent_id' => $foreign->id])
            ->assertHasErrors(["Parent task {$foreign->id} is not in this project."]);
        $this->assertDatabaseMissing('tasks', ['title' => 'Fremd']);
    }

    public function test_bad_names_are_refused_with_a_hint_and_nothing_is_saved(): void
    {
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'A', 'status' => 'Gibtsnicht'])
            ->assertHasErrors(['Unknown status "Gibtsnicht". Available: Offen, In Arbeit, Erledigt.']);
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'B', 'assignee' => 'niemand@example.com'])
            ->assertSee('is not an active member');
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'C', 'tags' => ['Nix']])->assertSee('Unknown tag "Nix"');
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'D', 'fields' => ['Priorität' => 'Mega']])->assertSee('Unknown option "Mega"');
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'E', 'fields' => ['Nix' => '1']])->assertSee('Unknown field "Nix"');
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'F', 'start_date' => '2026-12-02', 'due_date' => '2026-12-01'])
            ->assertSee('start date must not be after');

        $this->assertSame(0, Task::count());
    }

    public function test_input_is_validated(): void
    {
        $this->tool(CreateTask::class, ['project_id' => $this->project->id])->assertHasErrors();
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => str_repeat('x', 256)])->assertHasErrors();
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'x', 'due_date' => 'bald'])->assertHasErrors();
    }

    public function test_tasks_of_projects_one_cannot_see_cannot_be_changed_or_commented(): void
    {
        $foreign = Task::factory()->for(Project::factory()->create())->create(['title' => 'Fremd']);

        $this->tool(UpdateTask::class, ['task_id' => $foreign->id, 'title' => 'Geändert'])->assertHasErrors(["Task {$foreign->id} not found."]);
        $this->tool(AddComment::class, ['task_id' => $foreign->id, 'body' => 'Hallo'])->assertHasErrors(["Task {$foreign->id} not found."]);

        $this->assertSame('Fremd', $foreign->fresh()->title);
        $this->assertSame(0, $foreign->comments()->count());
    }

    public function test_viewers_foreign_and_archived_projects_cannot_be_written_to(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $task = Task::factory()->for($this->project)->create();
        $foreign = Project::factory()->create();

        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'X'], $viewer)->assertSee('may not change');
        $this->tool(UpdateTask::class, ['task_id' => $task->id, 'title' => 'X'], $viewer)->assertSee('may not change');
        $this->tool(AddComment::class, ['task_id' => $task->id, 'body' => 'X'], $viewer)->assertSee('may not change');
        $this->tool(CreateTask::class, ['project_id' => $foreign->id, 'title' => 'X'])->assertSee("Project {$foreign->id} not found.");

        $this->project->update(['archived_at' => now()]);
        $this->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'X'])->assertSee('archived');

        $this->assertSame(1, Task::count());
        $this->assertSame($task->title, $task->fresh()->title);
    }

    public function test_updating_changes_only_what_is_given(): void
    {
        $anna = User::factory()->create(['email' => 'anna@example.com']);
        $this->project->setRole($anna, ProjectRole::Editor);
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        $task = Task::factory()->for($this->project)->create(['title' => 'Alt', 'description' => 'Bleibt', 'assignee_id' => $anna->id, 'due_date' => '2026-12-01']);
        $task->tags()->attach($tag);

        $this->tool(UpdateTask::class, ['task_id' => $task->id, 'title' => 'Neu', 'status' => 'Erledigt'])->assertOk()->assertSee(['Neu', 'Erledigt']);

        $task->refresh();
        $this->assertSame('Neu', $task->title);
        $this->assertSame('Bleibt', $task->description);
        $this->assertSame($anna->id, $task->assignee_id);
        $this->assertSame('2026-12-01', $task->due_date->toDateString());
        $this->assertTrue($task->isDone());
        $this->assertSame([$tag->id], $task->tags()->pluck('tags.id')->all());
        $this->assertContains('hat den Status von „Offen“ auf „Erledigt“ geändert', $task->activities()->get()->map->sentence()->all());
    }

    public function test_updating_can_clear_and_replace_things(): void
    {
        $anna = User::factory()->create(['email' => 'anna@example.com']);
        $this->project->setRole($anna, ProjectRole::Editor);
        $bug = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        $idea = Tag::factory()->for($this->project)->create(['name' => 'Idee']);
        $task = Task::factory()->for($this->project)->create(['description' => 'Weg', 'assignee_id' => $anna->id, 'due_date' => '2026-12-01']);
        $task->tags()->attach($bug);
        $task->collaborators()->attach($this->user);

        $this->tool(UpdateTask::class, [
            'task_id' => $task->id, 'description' => '', 'assignee' => '', 'due_date' => '',
            'tags' => ['Idee'], 'collaborators' => [], 'fields' => ['Priorität' => 'Dringend'],
        ])->assertOk();

        $task->refresh();
        $this->assertNull($task->description);
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);
        $this->assertSame([$idea->id], $task->tags()->pluck('tags.id')->all());
        $this->assertSame(0, $task->collaborators()->count());
        $this->assertSame('Dringend', $task->fieldValues()->firstOrFail()->option->name);

        $this->tool(UpdateTask::class, ['task_id' => $task->id, 'fields' => ['Priorität' => '']])->assertOk();
        $this->assertSame(0, $task->fieldValues()->count());
    }

    public function test_a_failed_update_changes_nothing(): void
    {
        $task = Task::factory()->for($this->project)->create(['title' => 'Alt']);

        $this->tool(UpdateTask::class, ['task_id' => $task->id, 'title' => 'Neu', 'tags' => ['Nix']])->assertSee('Unknown tag');

        $this->assertSame('Alt', $task->fresh()->title);
    }

    public function test_comments_are_written_as_the_token_owner(): void
    {
        $task = Task::factory()->for($this->project)->create();

        $this->tool(AddComment::class, ['task_id' => $task->id, 'body' => 'Erledigt, bitte prüfen.'])->assertOk();

        $comment = $task->comments()->firstOrFail();
        $this->assertSame($this->user->id, $comment->user_id);
        $this->assertSame('Erledigt, bitte prüfen.', $comment->body);
        $this->tool(AddComment::class, ['task_id' => $task->id, 'body' => ''])->assertHasErrors();
    }

    public function test_application_admins_can_work_in_every_project(): void
    {
        $admin = User::factory()->admin()->create();
        $other = Project::factory()->create();

        $this->tool(CreateTask::class, ['project_id' => $other->id, 'title' => 'Vom Admin'], $admin)->assertOk();

        $this->assertSame($admin->id, Task::where('title', 'Vom Admin')->firstOrFail()->creator_id);
    }
}
