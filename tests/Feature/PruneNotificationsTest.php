<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_old_read_notifications_are_removed(): void
    {
        $user = User::factory()->create();
        $notification = fn (?string $readAt) => $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => [],
            'read_at' => $readAt,
        ]);

        $notification(now()->subDays(91)->toDateTimeString());
        $recentRead = $notification(now()->subDays(10)->toDateTimeString());
        $unread = $notification(null);

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertSame(
            collect([$recentRead->id, $unread->id])->sort()->values()->all(),
            $user->notifications()->pluck('id')->sort()->values()->all(),
        );
    }
}
