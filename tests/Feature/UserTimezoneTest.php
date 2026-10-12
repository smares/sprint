<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class UserTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Berlin']);
        date_default_timezone_set('Europe/Berlin');
    }

    public function test_without_a_zone_of_their_own_people_follow_the_application(): void
    {
        $user = User::factory()->make(['timezone' => null]);

        $this->assertSame('Europe/Berlin', $user->preferredTimezone());
    }

    public function test_quiet_times_apply_in_the_persons_own_time_zone(): void
    {
        $quiet = ['quiet_from' => '22:00', 'quiet_until' => '07:00'];
        $berlin = User::factory()->make($quiet);
        $newYork = User::factory()->make($quiet + ['timezone' => 'America/New_York']);

        // 23:00 in Berlin is 17:00 in New York
        $moment = Carbon::parse('2026-10-14 21:00', 'UTC');

        $this->assertTrue($berlin->isQuietAt($moment));
        $this->assertFalse($newYork->isQuietAt($moment));
        $this->assertTrue($newYork->isQuietAt(Carbon::parse('2026-10-15 03:00', 'UTC')));
    }

    public function test_an_absence_starts_by_the_persons_own_calendar(): void
    {
        $away = ['absent_from' => '2026-10-13', 'absent_until' => '2026-10-16'];
        $berlin = User::factory()->create($away);
        $newYork = User::factory()->create($away + ['timezone' => 'America/New_York']);

        // Already the 13th in Berlin, still the 12th in New York
        $this->travelTo(Carbon::parse('2026-10-12 23:30', 'UTC'));

        $this->assertTrue($berlin->isAbsent());
        $this->assertFalse($newYork->isAbsent());
    }

    public function test_the_profile_sets_and_clears_the_time_zone(): void
    {
        $user = User::factory()->create(['quiet_from' => '22:00', 'quiet_until' => '07:00']);
        $this->actingAs($user);

        Livewire::test('pages::profile')
            ->assertSee('Zeitzone Europe/Berlin')
            ->set('timezone', 'America/New_York')
            ->assertHasNoErrors()
            ->assertSee('Zeitzone America/New York');
        $this->assertSame('America/New_York', $user->fresh()->timezone);

        Livewire::test('pages::profile')
            ->set('timezone', 'Mars/Olympus_Mons')
            ->assertHasErrors('timezone')
            ->set('timezone', '')
            ->assertHasNoErrors();
        $this->assertNull($user->fresh()->timezone);
    }
}
