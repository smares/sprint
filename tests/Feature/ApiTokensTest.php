<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokensTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_the_profile_offers_token_management(): void
    {
        $this->get(route('profile'))->assertOk()->assertSee('API-Zugang für KI-Agenten');
    }

    public function test_creating_a_token_shows_it_once_with_the_connect_command(): void
    {
        $this->freezeTime();

        $page = Livewire::test('api-tokens')->set('name', 'Laptop')->call('create')->assertHasNoErrors();

        $plain = $page->get('createdToken');
        $this->assertNotEmpty($plain);
        $page->assertSee($plain)->assertSee(url('/mcp'));

        $token = $this->user->tokens()->firstOrFail();
        $this->assertSame('Laptop', $token->name);
        $this->assertTrue($token->can('write'));
        $this->assertSame(now()->addDays(90)->toDateString(), $token->expires_at->toDateString());
        $this->assertNotSame($plain, $token->token);

        $page->call('dismissToken')->assertSet('createdToken', null)->assertDontSee($plain);
    }

    public function test_tokens_can_be_read_only_and_unlimited(): void
    {
        Livewire::test('api-tokens')->set('name', 'Lesen')->set('readOnly', true)->set('expiry', 'never')->call('create');

        $token = $this->user->tokens()->firstOrFail();
        $this->assertTrue($token->can('read'));
        $this->assertFalse($token->can('write'));
        $this->assertNull($token->expires_at);
    }

    public function test_a_name_and_a_valid_lifetime_are_required(): void
    {
        Livewire::test('api-tokens')->set('name', '')->call('create')->assertHasErrors('name');
        Livewire::test('api-tokens')->set('name', 'x')->set('expiry', '9999')->call('create')->assertHasErrors('expiry');

        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_only_the_own_tokens_are_listed_and_revocable(): void
    {
        $mine = $this->user->createToken('Meiner', ['*'])->accessToken;
        $foreign = User::factory()->create()->createToken('Fremder', ['*'])->accessToken;

        $page = Livewire::test('api-tokens')->assertSee('Meiner')->assertDontSee('Fremder');

        $page->call('revoke', $foreign->id);
        $this->assertNotNull(PersonalAccessToken::find($foreign->id));

        $page->call('revoke', $mine->id)->assertDontSee('Meiner');
        $this->assertNull(PersonalAccessToken::find($mine->id));
    }
}
