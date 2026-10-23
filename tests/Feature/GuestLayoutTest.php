<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_content_of_pages_without_the_sidebar_is_marked_to_stand_in_the_middle(): void
    {
        $this->get(route('login'))->assertSee('id="main-content"', false)->assertSee('data-guest', false);

        $this->actingAs(User::factory()->create())->get(route('projects.index'))->assertSee('id="main-content"', false)->assertDontSee('data-guest', false);
    }
}
