<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Notifications\InboxPush;
use App\Notifications\TaskCommented;
use App\Notifications\TaskStatusChanged;
use App\Notifications\UserMentioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Tests\TestCase;

class QueuedNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, Notification>
     */
    private function notifications(): array
    {
        $task = Task::factory()->create();
        $comment = Comment::factory()->create(['task_id' => $task->id]);
        $entry = new DatabaseNotification(['data' => ['task_id' => $task->id]]);

        return [
            'comment' => new TaskCommented($comment),
            'status' => new TaskStatusChanged($task, 'Offen', 'Erledigt'),
            'mention' => new UserMentioned($task, 'comment', 'Hallo'),
            'push' => new InboxPush($entry),
            'digest' => new DailyDigest(['overdue' => new Collection([$task]), 'today' => new Collection, 'upcoming' => new Collection]),
        ];
    }

    public function test_queued_notifications_are_dropped_when_their_models_are_deleted_before_sending(): void
    {
        $user = User::factory()->create();

        foreach ($this->notifications() as $name => $notification) {
            $job = new SendQueuedNotifications($user, $notification);

            $this->assertTrue($job->deleteWhenMissingModels, $name);
        }
    }
}
