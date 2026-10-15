<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarkdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_placeholders_written_as_html_entities_do_not_break_the_rendering(): void
    {
        $user = User::factory()->create(['name' => 'Anna']);

        // The converter decodes the entities after the stripping, so a real mention next to them must still render
        $html = (string) MarkdownService::render("@[Anna](user:{$user->id}) &#xE000;7&#xE001; Hallo");

        $this->assertStringContainsString('Anna', $html);
        $this->assertStringContainsString('Hallo', $html);
    }

    public function test_a_preview_is_not_kept_in_the_cache_but_a_saved_text_is(): void
    {
        $text = str_repeat('Ein längerer Absatz. ', 150);
        $key = 'markdown:1:'.hash('xxh128', trim($text));

        MarkdownService::render($text, cache: false);
        $this->assertFalse(Cache::has($key));

        MarkdownService::render($text);
        $this->assertTrue(Cache::has($key));
    }
}
