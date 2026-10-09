<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->create(['email' => 'anna@example.com', 'password' => 'altes-passwort', 'locale' => 'en']);
    }

    public function test_the_login_page_links_to_the_form(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'));
        $this->get(route('password.request'))->assertOk()->assertSee('Passwort vergessen');
    }

    public function test_a_link_is_sent_in_the_persons_language_and_the_answer_does_not_reveal_accounts(): void
    {
        Livewire::test('pages::forgot-password')->set('email', 'ANNA@example.com')->call('send')->assertSet('sent', true);
        Livewire::test('pages::forgot-password')->set('email', 'niemand@example.com')->call('send')->assertSet('sent', true);

        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification): bool {
            $mail = $notification->toMail($this->user);

            return str_contains($mail->subject, config('app.name'))
                && str_contains((string) $mail->render(), route('password.reset', ['token' => $notification->token, 'email' => 'anna@example.com']));
        });
        Notification::assertCount(1);
    }

    public function test_deactivated_people_get_no_link(): void
    {
        $this->user->deactivate();

        Livewire::test('pages::forgot-password')->set('email', 'anna@example.com')->call('send')->assertSet('sent', true);

        Notification::assertNothingSent();
    }

    public function test_requests_are_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            Livewire::test('pages::forgot-password')->set('email', "x{$attempt}@example.com")->call('send')->assertHasNoErrors();
        }

        Livewire::test('pages::forgot-password')->set('email', 'anna@example.com')->call('send')->assertHasErrors('email');
    }

    public function test_the_link_sets_a_new_password_and_signs_out_everywhere(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'alt', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);
        $this->user->createToken('Laptop');
        $token = Password::broker()->createToken($this->user);

        $this->get(route('password.reset', ['token' => $token, 'email' => 'anna@example.com']))->assertOk()->assertSee('Neues Passwort festlegen');

        Livewire::test('pages::reset-password', ['token' => $token])
            ->set('email', 'anna@example.com')
            ->set('password', 'neues-passwort')->set('passwordConfirmation', 'neues-passwort')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('neues-passwort', $this->user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'alt']);
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_wrong_tokens_and_weak_passwords_are_refused(): void
    {
        $token = Password::broker()->createToken($this->user);

        Livewire::test('pages::reset-password', ['token' => 'falsch'])
            ->set('email', 'anna@example.com')->set('password', 'neues-passwort')->set('passwordConfirmation', 'neues-passwort')
            ->call('save')->assertHasErrors('email');

        Livewire::test('pages::reset-password', ['token' => $token])
            ->set('email', 'anna@example.com')->set('password', 'kurz')->set('passwordConfirmation', 'kurz')
            ->call('save')->assertHasErrors('password');

        $this->assertTrue(Hash::check('altes-passwort', $this->user->fresh()->password));
    }

    public function test_signed_in_people_are_sent_away_from_the_forms(): void
    {
        $this->actingAs($this->user)->get(route('password.request'))->assertRedirect();
    }

    public function test_a_wrong_link_does_not_reveal_whether_the_address_has_an_account(): void
    {
        $known = Livewire::test('pages::reset-password', ['token' => 'falsch'])
            ->set('email', 'anna@example.com')->set('password', 'neues-passwort')->set('passwordConfirmation', 'neues-passwort')
            ->call('save')->errors()->first('email');

        $unknown = Livewire::test('pages::reset-password', ['token' => 'falsch'])
            ->set('email', 'niemand@example.com')->set('password', 'neues-passwort')->set('passwordConfirmation', 'neues-passwort')
            ->call('save')->errors()->first('email');

        $this->assertSame($known, $unknown);
        $this->assertSame(__(Password::INVALID_TOKEN), $unknown);
    }
}
