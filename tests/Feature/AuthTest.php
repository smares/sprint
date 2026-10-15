<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_user_can_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        Livewire::test('pages::login')
            ->set('email', $user->email)
            ->set('password', 'secret-pass')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('projects.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::test('pages::login')
            ->set('email', $user->email)
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_address_gets_the_same_error_and_the_same_minimum_duration(): void
    {
        $unknown = Livewire::test('pages::login')->set('email', 'niemand@example.com')->set('password', 'wrong')->call('login');

        $known = Livewire::test('pages::login')->set('email', User::factory()->create()->email)->set('password', 'wrong')->call('login');

        $this->assertSame($known->errors()->first('email'), $unknown->errors()->first('email'));
        $this->assertGuest();
        Sleep::assertSleptTimes(2);
    }

    public function test_a_deactivated_account_is_refused_after_the_password_check(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass', 'deactivated_at' => now()]);

        Livewire::test('pages::login')->set('email', $user->email)->set('password', 'wrong')->call('login')
            ->assertHasErrors('email');
        Livewire::test('pages::login')->set('email', $user->email)->set('password', 'secret-pass')->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_successful_login_does_not_wait(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        Livewire::test('pages::login')->set('email', $user->email)->set('password', 'secret-pass')->call('login');

        Sleep::assertNeverSlept();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        $component = Livewire::test('pages::login')
            ->set('email', $user->email)
            ->set('password', 'wrong');

        foreach (range(1, 5) as $ignored) {
            $component->call('login');
        }

        $component->call('login')->assertHasErrors('email');
        $this->assertStringContainsString('Zu viele Versuche', $component->errors()->first('email'));
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_create_user_command_creates_a_user(): void
    {
        $this->artisan('user:create', ['name' => 'Max Muster', 'email' => 'max@example.com', '--password' => 'geheim1234'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'max@example.com']);
    }

    public function test_create_user_command_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'max@example.com']);

        $this->artisan('user:create', ['name' => 'Max', 'email' => 'max@example.com'])
            ->assertFailed();
    }
}
