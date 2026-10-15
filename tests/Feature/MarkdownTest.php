<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarkdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_typed_placeholder_characters_do_not_break_the_rendering_of_a_mention(): void
    {
        $user = User::factory()->create(['name' => 'Anna']);

        // The private-use characters stand for mentions internally; typed by hand they must not point at one that is not there
        $html = (string) MarkdownService::render("@[Anna](user:{$user->id}) \u{E000}7\u{E001} Hallo");

        $this->assertStringContainsString('Anna', $html);
        $this->assertStringContainsString('7 Hallo', $html);
        $this->assertStringNotContainsString("\u{E000}", $html);
        $this->assertStringNotContainsString("\u{E001}", $html);
    }
}
