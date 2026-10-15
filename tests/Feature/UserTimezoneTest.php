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

    public function test_names_that_browsers_still_report_are_saved_under_their_current_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Chrome still says Asia/Calcutta for Asia/Kolkata, which the list of PHP does not have under that name
        Livewire::test('pages::profile')
            ->set('timezone', 'Asia/Calcutta')
            ->assertHasNoErrors()
            ->assertSet('timezone', 'Asia/Kolkata');

        $this->assertSame('Asia/Kolkata', $user->fresh()->timezone);
        $this->assertSame('Asia/Kolkata', User::canonicalTimezone('Asia/Calcutta'));
        $this->assertSame('Europe/Berlin', User::canonicalTimezone('Europe/Berlin'));

        foreach (User::LEGACY_TIMEZONES as $legacy => $current) {
            $this->assertContains($current, timezone_identifiers_list());
            $this->assertNotContains($legacy, timezone_identifiers_list(), "{$legacy} is a name PHP knows, it needs no entry");
        }
    }

    public function test_a_zone_that_is_refused_leaves_the_select_on_the_saved_zone(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Berlin']);
        $this->actingAs($user);

        Livewire::test('pages::profile')
            ->set('timezone', 'Mars/Olympus_Mons')
            ->assertHasErrors('timezone')
            ->assertSet('timezone', 'Europe/Berlin');

        $this->assertSame('Europe/Berlin', $user->fresh()->timezone);
    }

    public function test_an_absence_may_end_today_by_the_persons_own_calendar(): void
    {
        // 03:00 on the 16th in Berlin is still the evening of the 15th in Los Angeles
        $this->travelTo(Carbon::parse('2026-10-16 03:00', 'Europe/Berlin'));
        $user = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        $this->actingAs($user);

        Livewire::test('pages::profile')
            ->set('absentFrom', '2026-10-15')
            ->set('absentUntil', '2026-10-15')
            ->call('saveAbsence')
            ->assertHasNoErrors();

        $this->assertSame('2026-10-15', $user->fresh()->absent_until->toDateString());

        Livewire::test('pages::profile')
            ->set('absentFrom', '2026-10-14')
            ->set('absentUntil', '2026-10-14')
            ->call('saveAbsence')
            ->assertHasErrors('absentUntil');
    }
}
