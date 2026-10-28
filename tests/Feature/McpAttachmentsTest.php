<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Mcp\Servers\SprintServer;
use App\Mcp\Tools\AddAttachment;
use App\Mcp\Tools\GetTask;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->user, ProjectRole::Editor);
        $this->task = Task::factory()->for($this->project)->create();
    }

    private function tool(array $arguments, ?User $as = null): TestResponse
    {
        return SprintServer::actingAs($as ?? $this->user)->tool(AddAttachment::class, ['task_id' => $this->task->id] + $arguments);
    }

    /**
     * Asks for an upload link over HTTP with a real token and returns it.
     */
    private function link(string $name = 'bericht.txt', ?string $token = null): string
    {
        $token ??= $this->user->createToken('Agent', ['read', 'write'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'add-attachment', 'arguments' => (object) ['task_id' => $this->task->id, 'name' => $name]],
        ], ['Accept' => 'application/json, text/event-stream'])->assertOk();

        $link = json_decode((string) $response->json('result.content.0.text'), true);

        $this->assertSame('PUT', $link['method']);
        $this->assertTrue($link['single_use']);
        $this->assertStringContainsString('curl -T', $link['how']);

        return $link['upload_url'];
    }

    public function test_a_small_file_arrives_in_the_tool_call_and_shows_up_in_get_task(): void
    {
        $this->tool(['name' => 'notizen.txt', 'content_base64' => base64_encode("Hallo Welt\n")])->assertOk()->assertSee('notizen.txt');

        $attachment = $this->task->attachments()->sole();
        $this->assertSame('notizen.txt', $attachment->name);
        $this->assertSame('text/plain', $attachment->mime_type);
        $this->assertSame(11, $attachment->size);
        $this->assertSame($this->user->id, $attachment->user_id);
        Storage::assertExists($attachment->path);
        $this->assertSame(['names' => ['notizen.txt']], $this->task->activities()->where('type', 'attachments_added')->sole()->data);

        SprintServer::actingAs($this->user)->tool(GetTask::class, ['task_id' => $this->task->id])->assertSee('notizen.txt');
    }

    public function test_the_name_is_cleaned_and_the_type_comes_from_the_content(): void
    {
        $this->tool(['name' => "../../etc/pass\u{0000}wd.png", 'content_base64' => base64_encode('kein Bild')])->assertOk();

        $attachment = $this->task->attachments()->sole();
        $this->assertSame('passwd.png', $attachment->name);
        $this->assertSame('text/plain', $attachment->mime_type);
        $this->assertStringStartsWith("attachments/{$this->project->id}/{$this->task->id}/", $attachment->path);
    }

    public function test_content_must_be_valid_and_small(): void
    {
        $this->tool(['name' => 'a.txt', 'content_base64' => '***kein base64***'])->assertHasErrors(['not valid Base64']);
        $this->tool(['name' => 'a.txt', 'content_base64' => base64_encode(str_repeat('x', Attachment::MAX_INLINE_BYTES + 1))])->assertHasErrors(['too large']);
        $this->tool(['name' => 'a.txt', 'content_base64' => str_repeat('A', 2_000_000)])->assertHasErrors(['too large']);

        $this->assertSame(0, $this->task->attachments()->count());
    }

    public function test_only_people_who_may_change_the_task_can_attach(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $outsider = User::factory()->create();

        $this->tool(['name' => 'a.txt', 'content_base64' => base64_encode('x')], $viewer)->assertHasErrors(['may not change']);
        $this->tool(['name' => 'a.txt', 'content_base64' => base64_encode('x')], $outsider)->assertHasErrors(['not found']);

        $this->assertSame(0, $this->task->attachments()->count());
    }

    public function test_a_read_only_token_cannot_attach_nor_ask_for_a_link(): void
    {
        $token = $this->user->createToken('Lesen', ['read'])->plainTextToken;

        foreach ([['name' => 'a.txt', 'content_base64' => base64_encode('x')], ['name' => 'a.txt']] as $arguments) {
            $this->withToken($token)->postJson('/mcp', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'add-attachment', 'arguments' => (object) (['task_id' => $this->task->id] + $arguments)],
            ], ['Accept' => 'application/json, text/event-stream'])->assertSee('read-only');
        }
    }

    public function test_a_larger_file_goes_through_a_link_that_works_once(): void
    {
        $url = $this->link('bericht.txt');

        $this->assertMatchesRegularExpression('#/mcp-uploads/[A-Za-z0-9]{40}$#', $url);

        $this->call('PUT', $url, [], [], [], [], "Zeile 1\nZeile 2\n")->assertCreated()->assertJsonPath('name', 'bericht.txt')->assertJsonPath('size', 16);

        $attachment = $this->task->attachments()->sole();
        $this->assertSame($this->user->id, $attachment->user_id);
        $this->assertSame('text/plain', $attachment->mime_type);
        Storage::assertExists($attachment->path);
        $this->assertSame(1, $this->task->activities()->where('type', 'attachments_added')->count());

        $this->call('PUT', $url, [], [], [], [], 'noch einmal')->assertNotFound()->assertJsonPath('message', 'This link is invalid, expired or already used.');
        $this->assertSame(1, $this->task->attachments()->count());
    }

    public function test_nothing_in_the_request_can_change_who_uploads_or_what_the_file_is_called(): void
    {
        $other = User::factory()->create();
        $this->project->setRole($other, ProjectRole::Editor);
        $url = $this->link('echt.txt');

        $this->call('PUT', $url.'?user='.$other->id.'&name=falsch.txt&task=999', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['user' => $other->id, 'name' => 'falsch.txt']))->assertCreated();

        $attachment = $this->task->attachments()->sole();
        $this->assertSame($this->user->id, $attachment->user_id);
        $this->assertSame('echt.txt', $attachment->name);
        $this->assertSame($this->user->id, $this->task->activities()->where('type', 'attachments_added')->sole()->user_id);
    }

    public function test_unknown_and_expired_links_are_refused(): void
    {
        $this->call('PUT', route('mcp.uploads.store', str_repeat('a', 40)), [], [], [], [], 'x')->assertNotFound();
        $this->call('PUT', '/mcp-uploads/kurz', [], [], [], [], 'x')->assertNotFound();

        $url = $this->link();
        $this->travel(11)->minutes();
        $this->call('PUT', $url, [], [], [], [], 'x')->assertNotFound();

        $this->assertSame(0, $this->task->attachments()->count());
    }

    public function test_the_link_stops_working_when_the_person_or_the_token_may_no_longer_do_it(): void
    {
        $token = $this->user->createToken('Agent', ['read', 'write']);

        $revoked = $this->link('a.txt', $token->plainTextToken);
        $token->accessToken->delete();
        $this->call('PUT', $revoked, [], [], [], [], 'x')->assertForbidden();

        $viewerLink = $this->link();
        $this->project->setRole($this->user, ProjectRole::Viewer);
        $this->call('PUT', $viewerLink, [], [], [], [], 'x')->assertForbidden();

        $this->project->setRole($this->user, ProjectRole::Editor);
        $this->user->update(['deactivated_at' => now()]);
        $this->call('PUT', $viewerLink, [], [], [], [], 'x')->assertForbidden();

        $this->assertSame(0, $this->task->attachments()->count());
    }

    public function test_a_failed_upload_does_not_use_up_the_link(): void
    {
        $url = $this->link();

        $this->call('PUT', $url, [], [], [], [], '')->assertStatus(422);
        $this->call('PUT', $url, [], [], [], [], str_repeat('x', Attachment::MAX_KILOBYTES * 1024 + 1))->assertStatus(413);
        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], "--x\r\n--x--")->assertStatus(415)->assertJsonPath('message', fn (string $message) => str_contains($message, 'curl -T'));
        $this->assertSame(0, $this->task->attachments()->count());

        $this->call('PUT', $url, [], [], [], [], 'jetzt richtig')->assertCreated();
        $this->assertSame(1, $this->task->attachments()->count());
    }

    public function test_the_link_needs_no_session_and_no_token(): void
    {
        $url = $this->link();

        $this->call('PUT', $url, [], [], [], [], 'ohne Anmeldung')->assertCreated()->assertCookieMissing(config('session.cookie'));
    }
}
