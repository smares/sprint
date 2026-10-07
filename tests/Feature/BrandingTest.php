<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_is_called_sprint(): void
    {
        $this->assertSame('Sprint', config('app.name'));
    }

    public function test_page_titles_end_with_the_product_name(): void
    {
        $project = Project::factory()->create(['name' => 'Website']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('<title>Website – Sprint</title>', false);
    }
}
