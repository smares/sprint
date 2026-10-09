<?php

namespace Tests\Feature;

use App\Emoji;
use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Reaction;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ReactionReceived;
use App\Services\InboxTextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ReactionTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $anna;

    private User $bernd;

    private User $viewer;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->anna = User::factory()->create(['name' => 'Anna']);
        $this->bernd = User::factory()->create(['name' => 'Bernd']);
        $this->viewer = User::factory()->create(['name' => 'Vera']);
        $this->project->setRole($this->anna, ProjectRole::Editor);
        $this->project->setRole($this->bernd, ProjectRole::Editor);
        $this->project->setRole($this->viewer, ProjectRole::Viewer);
        $this->task = Task::factory()->for($this->project)->create(['creator_id' => $this->anna->id, 'title' => 'Angebot']);
    }

    private function page(User $user): Testable
    {
        return Livewire::actingAs($user)->test('pages::tasks.show', ['task' => $this->task]);
    }

    public function test_reacting_to_a_task_and_taking_it_back(): void
    {
        $page = $this->page($this->bernd);

        $page->call('react', 'task', $this->task->id, '👍');
        $this->assertSame('👍', $this->task->reactions()->sole()->emoji);

        $page->call('react', 'task', $this->task->id, '👍');
        $this->assertSame(0, $this->task->reactions()->count());
    }

    public function test_one_emoji_per_person_another_one_replaces_it(): void
    {
        $page = $this->page($this->bernd);

        $page->call('react', 'task', $this->task->id, '👍')->call('react', 'task', $this->task->id, '❤️');

        $this->assertSame('❤️', $this->task->reactions()->sole()->emoji);

        $this->page($this->anna)->call('react', 'task', $this->task->id, '❤️');
        $this->assertSame(2, $this->task->reactions()->count());
    }

    public function test_the_database_allows_only_one_reaction_per_person_and_target(): void
    {
        Reaction::factory()->create(['user_id' => $this->bernd->id, 'reactable_type' => Task::class, 'reactable_id' => $this->task->id]);

        $this->expectException(QueryException::class);
        Reaction::factory()->create(['user_id' => $this->bernd->id, 'reactable_type' => Task::class, 'reactable_id' => $this->task->id, 'emoji' => '❤️']);
    }

    public function test_comments_can_be_reacted_to_and_each_has_its_own_reactions(): void
    {
        $first = Comment::factory()->for($this->task)->create(['user_id' => $this->anna->id]);
        $second = Comment::factory()->for($this->task)->create(['user_id' => $this->anna->id]);

        $this->page($this->bernd)->call('react', 'comment', $first->id, '🎉');

        $this->assertSame(1, $first->reactions()->count());
        $this->assertSame(0, $second->reactions()->count());
    }

    public function test_only_people_who_may_edit_react_and_only_in_this_task(): void
    {
        $this->page($this->viewer)->call('react', 'task', $this->task->id, '👍')->assertForbidden();
        $this->assertSame(0, Reaction::query()->count());

        $foreign = Comment::factory()->create();
        $this->page($this->bernd)->call('react', 'comment', $foreign->id, '👍')->assertNotFound();
        $this->page($this->bernd)->call('react', 'task', $this->task->id, 'poop');
        $this->page($this->bernd)->call('react', 'project', $this->task->id, '❤️')->assertNotFound();
        $this->assertSame(0, Reaction::query()->count());
    }

    public function test_any_single_emoji_works_and_other_input_is_turned_down(): void
    {
        $page = $this->page($this->bernd);

        foreach (['🚀', '👩‍👩‍👧‍👦', '👍🏽', '🇩🇪', '1️⃣', '🏴󠁧󠁢󠁥󠁮󠁧󠁿'] as $emoji) {
            $page->call('react', 'task', $this->task->id, " {$emoji} ");
            $this->assertSame($emoji, $this->task->reactions()->sole()->emoji);
        }

        foreach (['', 'abc', 'a👍', '👍👍', '<b>x</b>', '👍 👍', str_repeat('👍', 20)] as $text) {
            $page->call('react', 'task', $this->task->id, $text);
        }

        $this->assertSame('🏴󠁧󠁢󠁥󠁮󠁧󠁿', $this->task->reactions()->sole()->emoji);
        $this->assertSame(1, Reaction::query()->count());
    }

    public function test_emoji_normalize_accepts_exactly_one_emoji(): void
    {
        $this->assertSame('😀', Emoji::normalize('😀'));
        $this->assertSame("\u{1F468}\u{200D}\u{1F4BB}", Emoji::normalize("\u{1F468}\u{200D}\u{1F4BB}"));
        $this->assertNull(Emoji::normalize('A'));
        $this->assertNull(Emoji::normalize('1'));
        $this->assertNull(Emoji::normalize('😀x'));
        $this->assertNull(Emoji::normalize('😀😀'));
    }

    public function test_everyone_who_sees_the_task_sees_the_reactions_with_names(): void
    {
        $this->task->toggleReaction($this->bernd, '👀');
        $comment = Comment::factory()->for($this->task)->create(['user_id' => $this->anna->id]);
        $comment->toggleReaction($this->anna, '🙏');

        $this->actingAs($this->viewer)->get(route('tasks.show', $this->task))->assertOk()
            ->assertSee('👀 1', false)
            ->assertSee('🙏 1', false)
            ->assertSee('Bernd')
            ->assertDontSee('aria-label="React"', false);

        // On the task page the reactions sit inside the task's form, so they must not bring a form of their own
        $this->actingAs($this->bernd)->get(route('tasks.show', $this->task))->assertOk()
            ->assertSee('aria-label="Reagieren"', false)
            ->assertDontSee('<form x-data', false);
    }

    public function test_the_creator_gets_an_inbox_entry_but_no_mail(): void
    {
        Notification::fake();

        $this->task->toggleReaction($this->bernd, '👍');

        Notification::assertSentTo($this->anna, ReactionReceived::class, function (ReactionReceived $notification, array $channels): bool {
            $this->assertSame(['database'], $channels);

            return $notification->target === 'task' && $notification->emoji === '👍';
        });
    }

    public function test_the_author_of_a_comment_is_told_and_the_inbox_reads_in_their_language(): void
    {
        $comment = Comment::factory()->for($this->task)->create(['user_id' => $this->anna->id]);

        $comment->toggleReaction($this->bernd, '❤️');

        $entry = $this->anna->notifications()->sole();
        $this->assertSame($this->task->id, $entry->data['task_id']);
        $this->assertSame('Bernd hat mit ❤️ auf deinen Kommentar reagiert', InboxTextService::sentence($entry));

        $this->actingAs($this->anna)->get(route('inbox'))->assertOk()->assertSee('Bernd hat mit ❤️ auf deinen Kommentar reagiert');
    }

    public function test_changing_the_emoji_updates_the_unread_entry_instead_of_adding_one(): void
    {
        $this->task->toggleReaction($this->bernd, '👍');
        $this->task->toggleReaction($this->bernd, '🎉');

        $entry = $this->anna->notifications()->sole();
        $this->assertSame('🎉', $entry->data['emoji']);

        $this->task->toggleReaction($this->viewer, '👀');
        $this->assertSame(2, $this->anna->notifications()->count());

        $entry->markAsRead();
        $this->task->toggleReaction($this->bernd, '❤️');
        $this->assertSame(3, $this->anna->notifications()->count());
    }

    public function test_nobody_is_told_about_their_own_reaction_taking_it_back_or_when_the_task_is_muted(): void
    {
        $this->task->toggleReaction($this->anna, '👍');
        $this->assertSame(0, $this->anna->notifications()->count());

        $this->task->toggleReaction($this->bernd, '👍');
        $this->task->toggleReaction($this->bernd, '👍');
        $this->assertSame(1, $this->anna->notifications()->count());

        $this->task->notificationMutes()->attach($this->anna);
        $this->task->toggleReaction($this->viewer, '👀');
        $this->assertSame(1, $this->anna->notifications()->count());
    }

    public function test_reactions_go_when_the_task_or_comment_is_deleted(): void
    {
        $comment = Comment::factory()->for($this->task)->create(['user_id' => $this->anna->id]);
        $comment->toggleReaction($this->bernd, '❤️');
        $this->task->toggleReaction($this->bernd, '❤️');

        $comment->delete();
        $this->assertSame(1, Reaction::query()->count());

        $this->task->delete();
        $this->assertSame(0, Reaction::query()->count());
    }

    public function test_deleting_a_task_also_removes_the_reactions_of_its_comments_and_subtasks(): void
    {
        $child = Task::factory()->for($this->task->project)->create(['parent_id' => $this->task->id]);
        $grandchild = Task::factory()->for($this->task->project)->create(['parent_id' => $child->id]);
        $comment = Comment::factory()->for($child)->create(['user_id' => $this->anna->id]);
        $other = Task::factory()->for($this->task->project)->create();

        $comment->toggleReaction($this->bernd, '❤️');
        $grandchild->toggleReaction($this->bernd, '👍');
        $other->toggleReaction($this->bernd, '🎉');

        $this->task->delete();

        $this->assertSame(['🎉'], Reaction::query()->pluck('emoji')->all());
    }

    public function test_deleting_a_project_removes_all_its_reactions(): void
    {
        Comment::factory()->for($this->task)->create(['user_id' => $this->anna->id])->toggleReaction($this->bernd, '❤️');
        $this->task->toggleReaction($this->bernd, '👍');
        Task::factory()->create()->toggleReaction($this->bernd, '🎉');

        $this->task->project->delete();

        $this->assertSame(['🎉'], Reaction::query()->pluck('emoji')->all());
    }

    public function test_the_emoji_menu_is_only_built_when_opened(): void
    {
        Comment::factory()->for($this->task)->count(3)->create(['user_id' => $this->anna->id]);

        $html = $this->page($this->bernd)->html();

        $this->assertSame(4, substr_count($html, '<template x-if="built">'));
        $this->assertStringNotContainsString(__('Any other emoji …'), preg_replace('#<template x-if="built">.*?</template>#s', '', $html));
    }
}
