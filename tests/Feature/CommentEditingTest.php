<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\UserMentioned;
use App\Services\TaskSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class CommentEditingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Task $task;

    private User $author;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->author = User::factory()->create(['name' => 'Autorin']);
        $this->colleague = User::factory()->create(['name' => 'Kollege']);
        $this->project->setRole($this->author, ProjectRole::Editor);
        $this->project->setRole($this->colleague, ProjectRole::Editor);
        $this->task = Task::factory()->for($this->project)->create();
        $this->actingAs($this->author);
    }

    private function comment(string $body = 'Erster Entwurf', ?User $user = null): Comment
    {
        return Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => ($user ?? $this->author)->id, 'body' => $body]);
    }

    private function page()
    {
        return Livewire::test('pages::tasks.show', ['task' => $this->task]);
    }

    public function test_the_author_can_edit_a_comment_and_it_is_marked_as_edited(): void
    {
        $comment = $this->comment();
        $this->travel(5)->minutes();

        $this->page()->call('startEditComment', $comment->id)
            ->assertSet('editingCommentId', $comment->id)->assertSet('editingBody', 'Erster Entwurf')
            ->set('editingBody', 'Zweiter Entwurf')->call('saveComment')
            ->assertHasNoErrors()->assertSet('editingCommentId', null)
            ->assertSee('Zweiter Entwurf')->assertSee('bearbeitet');

        $this->assertSame('Zweiter Entwurf', $comment->fresh()->body);
        $this->assertTrue($comment->fresh()->wasEdited());
    }

    public function test_unedited_comments_are_not_marked(): void
    {
        $this->comment();

        $this->page()->assertDontSee('bearbeitet');
    }

    public function test_an_empty_or_huge_edit_is_rejected_and_nothing_changes(): void
    {
        $comment = $this->comment();

        $page = $this->page()->call('startEditComment', $comment->id);
        $page->set('editingBody', '')->call('saveComment')->assertHasErrors('editingBody');
        $page->set('editingBody', str_repeat('x', 5001))->call('saveComment')->assertHasErrors('editingBody');

        $this->assertSame('Erster Entwurf', $comment->fresh()->body);
    }

    public function test_cancelling_leaves_the_comment_alone(): void
    {
        $comment = $this->comment();

        $this->page()->call('startEditComment', $comment->id)->set('editingBody', 'Verworfen')->call('cancelEditComment')->assertSet('editingCommentId', null)->assertSet('editingBody', '');

        $this->assertSame('Erster Entwurf', $comment->fresh()->body);
    }

    public function test_other_people_cannot_edit_the_comment_not_even_project_admins(): void
    {
        $comment = $this->comment();
        $admin = User::factory()->create();
        $this->project->setRole($admin, ProjectRole::Admin);

        foreach ([$this->colleague, $admin] as $other) {
            $this->actingAs($other);
            Livewire::test('pages::tasks.show', ['task' => $this->task])->call('startEditComment', $comment->id)->assertForbidden();
        }

        $this->assertSame('Erster Entwurf', $comment->fresh()->body);
    }

    public function test_saving_a_forged_edit_of_someone_elses_comment_fails(): void
    {
        $comment = $this->comment();
        $this->actingAs($this->colleague);

        Livewire::test('pages::tasks.show', ['task' => $this->task])->set('editingCommentId', $comment->id)->set('editingBody', 'Gekapert')->call('saveComment')->assertForbidden();

        $this->assertSame('Erster Entwurf', $comment->fresh()->body);
    }

    public function test_viewers_cannot_edit_even_their_old_comments(): void
    {
        $comment = $this->comment();
        $this->project->setRole($this->author, ProjectRole::Viewer);

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('startEditComment', $comment->id)->assertForbidden();
    }

    public function test_the_author_and_project_admins_can_delete_but_not_colleagues(): void
    {
        $mine = $this->comment('Meins');
        $theirs = $this->comment('Seins', $this->colleague);

        $this->page()->call('deleteComment', $mine->id);
        $this->assertModelMissing($mine);

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('deleteComment', $theirs->id)->assertForbidden();
        $this->assertModelExists($theirs);

        $admin = User::factory()->create();
        $this->project->setRole($admin, ProjectRole::Admin);
        $this->actingAs($admin);
        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('deleteComment', $theirs->id);
        $this->assertModelMissing($theirs);
    }

    public function test_comments_of_other_tasks_cannot_be_reached(): void
    {
        $foreign = Comment::factory()->create(['task_id' => Task::factory()->for($this->project)->create()->id, 'user_id' => $this->author->id]);

        $this->page()->call('startEditComment', $foreign->id)->assertNotFound();
        $this->page()->call('deleteComment', $foreign->id)->assertNotFound();
    }

    public function test_buttons_are_only_shown_to_those_who_may_use_them(): void
    {
        $this->comment('Meins');
        $this->comment('Seins', $this->colleague);

        $this->page()->assertSeeHtml('aria-label="Kommentar bearbeiten"')->assertSeeHtml('aria-label="Kommentar löschen"');
        $this->assertSame(1, substr_count($this->page()->html(), 'aria-label="Kommentar bearbeiten"'));
        $this->assertSame(1, substr_count($this->page()->html(), 'aria-label="Kommentar löschen"'));

        $admin = User::factory()->create();
        $this->project->setRole($admin, ProjectRole::Admin);
        $this->actingAs($admin);
        $html = Livewire::test('pages::tasks.show', ['task' => $this->task])->html();
        $this->assertSame(0, substr_count($html, 'aria-label="Kommentar bearbeiten"'));
        $this->assertSame(2, substr_count($html, 'aria-label="Kommentar löschen"'));
    }

    public function test_only_new_mentions_in_an_edit_notify_people(): void
    {
        Notification::fake();
        $anna = User::factory()->create(['name' => 'Anna']);
        $ben = User::factory()->create(['name' => 'Ben']);
        $this->project->setRole($anna, ProjectRole::Editor);
        $this->project->setRole($ben, ProjectRole::Editor);
        $comment = $this->comment('Hallo @[Anna](user:'.$anna->id.')');
        Notification::assertSentToTimes($anna, UserMentioned::class, 1);

        $this->page()->call('startEditComment', $comment->id)
            ->set('editingBody', 'Hallo @[Anna](user:'.$anna->id.') und @[Ben](user:'.$ben->id.')')->call('saveComment');

        Notification::assertSentToTimes($anna, UserMentioned::class, 1);
        Notification::assertSentToTimes($ben, UserMentioned::class, 1);
    }

    public function test_the_search_index_follows_edits_and_deletes(): void
    {
        $comment = $this->comment('Zebrastreifen');
        $search = fn (string $query) => app(TaskSearch::class)->search($this->author, $query)->count();
        $this->assertSame(1, $search('zebrastreifen'));

        $this->page()->call('startEditComment', $comment->id)->set('editingBody', 'Giraffenfleck')->call('saveComment');
        $this->assertSame(0, $search('zebrastreifen'));
        $this->assertSame(1, $search('giraffenfleck'));

        $this->page()->call('deleteComment', $comment->id);
        $this->assertSame(0, $search('giraffenfleck'));
    }
}
