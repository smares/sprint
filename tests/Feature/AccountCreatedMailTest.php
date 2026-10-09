<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AccountCreatedMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_created_by_an_admin_gets_a_mail_with_the_name_of_the_admin(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create(['name' => 'Berta Boss']);

        Livewire::actingAs($admin)->test('pages::admin.users')
            ->set('name', 'Neu Nutzer')->set('email', 'neu@example.com')->set('locale', 'de')
            ->call('create')->assertHasNoErrors();

        $user = User::where('email', 'neu@example.com')->sole();
        Notification::assertSentTo($user, AccountCreated::class, fn (AccountCreated $notification) => $notification->createdBy === 'Berta Boss');
        Notification::assertSentToTimes($user, AccountCreated::class, 1);
    }

    public function test_the_mail_names_the_creator_and_holds_neither_a_password_nor_a_reset_link(): void
    {
        $user = User::factory()->create(['name' => 'Neu Nutzer', 'email' => 'neu@example.com', 'locale' => 'de']);

        $mail = (new AccountCreated('Berta Boss'))->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('Du wurdest in Sprint angelegt', $mail->subject);
        $this->assertStringContainsString('Berta Boss hat dich als Nutzer in Sprint angelegt.', $html);
        $this->assertStringContainsString(route('login'), $html);
        $this->assertStringNotContainsString('reset-password', $html);
        $this->assertStringNotContainsString('token', $html);
    }

    public function test_without_a_creator_the_mail_says_that_an_account_was_created(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $html = (string) (new AccountCreated)->toMail($user)->render();

        $this->assertStringContainsString('Für dich wurde ein Konto in Sprint angelegt.', $html);
    }

    public function test_the_command_sends_the_mail_too(): void
    {
        Notification::fake();

        $this->artisan('user:create', ['name' => 'Cli Nutzer', 'email' => 'cli@example.com'])->assertSuccessful();

        Notification::assertSentTo(User::where('email', 'cli@example.com')->sole(), AccountCreated::class, fn (AccountCreated $notification) => $notification->createdBy === null);
    }
}
