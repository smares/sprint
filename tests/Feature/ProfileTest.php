<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Anna Alt', 'email' => 'anna@example.com', 'password' => 'altes-passwort']);
        $this->actingAs($this->user);
    }

    public function test_the_page_needs_a_login_and_is_linked_in_the_menu(): void
    {
        $this->get(route('profile'))->assertOk()->assertSee('Anna Alt')->assertSee('anna@example.com');
        $this->get(route('projects.index'))->assertSee(route('profile'), false);

        auth()->logout();
        $this->get(route('profile'))->assertRedirect(route('login'));
    }

    public function test_the_name_can_be_changed_without_a_password(): void
    {
        Livewire::test('pages::profile')->set('name', '  Anna Neu ')->call('saveProfile')->assertHasNoErrors();

        $this->assertSame('Anna Neu', $this->user->fresh()->name);
        $this->assertSame('anna@example.com', $this->user->fresh()->email);
    }

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $page = Livewire::test('pages::profile')->set('email', 'neu@example.com');

        $page->call('saveProfile')->assertHasErrors('emailPassword');
        $page->set('emailPassword', 'falsch')->call('saveProfile')->assertHasErrors('emailPassword');
        $this->assertSame('anna@example.com', $this->user->fresh()->email);

        $page->set('emailPassword', 'altes-passwort')->call('saveProfile')->assertHasNoErrors();
        $this->assertSame('neu@example.com', $this->user->fresh()->email);
    }

    public function test_the_email_must_be_valid_and_unique(): void
    {
        User::factory()->create(['email' => 'besetzt@example.com']);

        Livewire::test('pages::profile')->set('email', 'besetzt@example.com')->set('emailPassword', 'altes-passwort')->call('saveProfile')->assertHasErrors('email');
        Livewire::test('pages::profile')->set('email', 'kaputt')->call('saveProfile')->assertHasErrors('email');
        Livewire::test('pages::profile')->set('name', '')->call('saveProfile')->assertHasErrors('name');

        $this->assertSame('anna@example.com', $this->user->fresh()->email);
    }

    public function test_keeping_the_same_email_in_other_letter_case_needs_no_password(): void
    {
        Livewire::test('pages::profile')->set('email', 'ANNA@example.com')->call('saveProfile')->assertHasNoErrors();
    }

    public function test_the_password_can_be_changed(): void
    {
        $this->user->forceFill(['remember_token' => 'alt'])->save();

        Livewire::test('pages::profile')
            ->set('currentPassword', 'altes-passwort')->set('newPassword', 'neues-passwort')->set('newPasswordConfirmation', 'neues-passwort')
            ->call('changePassword')->assertHasNoErrors()
            ->assertSet('currentPassword', '')->assertSet('newPassword', '');

        $this->assertTrue(Hash::check('neues-passwort', $this->user->fresh()->password));
        $this->assertNotSame('alt', $this->user->fresh()->remember_token);
    }

    public function test_the_password_change_checks_everything(): void
    {
        $change = fn (string $current, string $new, string $confirmation) => Livewire::test('pages::profile')
            ->set('currentPassword', $current)->set('newPassword', $new)->set('newPasswordConfirmation', $confirmation)->call('changePassword');

        $change('falsch', 'neues-passwort', 'neues-passwort')->assertHasErrors('currentPassword');
        $change('altes-passwort', 'kurz', 'kurz')->assertHasErrors('newPassword');
        $change('altes-passwort', 'neues-passwort', 'anderes-passwort')->assertHasErrors('newPassword');
        $change('altes-passwort', 'altes-passwort', 'altes-passwort')->assertHasErrors('newPassword');
        $change('', '', '')->assertHasErrors(['currentPassword', 'newPassword']);

        $this->assertTrue(Hash::check('altes-passwort', $this->user->fresh()->password));
    }

    public function test_it_only_ever_changes_the_own_account(): void
    {
        $other = User::factory()->create(['name' => 'Fremd']);

        Livewire::test('pages::profile')->set('name', 'Neuer Name')->call('saveProfile');

        $this->assertSame('Fremd', $other->fresh()->name);
    }
}
