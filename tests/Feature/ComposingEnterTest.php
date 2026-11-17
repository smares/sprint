<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Enter that submits or finishes something does nothing while an input method is composing text: it only confirms the composition.
 */
class ComposingEnterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_helper_takes_the_composition_of_safari_into_account(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        // Safari reports the confirming Enter after the composition has ended, but with the key code 229
        $this->assertStringContainsString('const isComposing = (event) => event.isComposing || event.keyCode === 229', $script);
        $this->assertStringContainsString("window.Alpine.magic('composing', () => isComposing)", $script);
        $this->assertStringContainsString('registerComposing()', $script);

        // The field for an emoji uses the same check for its Enter
        $this->assertMatchesRegularExpression('/send\(event\) \{\s+if \(isComposing\(event\)\) \{/', $script);
    }

    public function test_every_handler_of_enter_that_submits_asks_the_helper_first(): void
    {
        $views = [
            'components/quick-add.blade.php' => 'x-on:keydown.enter.prevent="if (! $composing($event)) { add() }"',
            'pages/tasks/⚡mine-board.blade.php' => 'x-on:keydown.enter.prevent="if (! $composing($event)) { finish(true,',
            'pages/tasks/⚡show.blade.php' => 'x-on:keydown.enter.prevent="if (! $composing($event)) { $el.form->requestSubmit() }"',
        ];

        foreach ($views as $view => $handler) {
            $this->assertStringContainsString(str_replace('->', '.', $handler), file_get_contents(resource_path("views/{$view}")), $view);
        }
    }

    public function test_no_handler_of_enter_in_the_views_acts_without_the_helper(): void
    {
        $unguarded = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/(?:x-on:|@)key(?:down|up)\.enter[.\w]*="([^"]*)"/', file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $expression) {
                // The field for an emoji asks in its own method (see the first test)
                if (! str_contains($expression, '$composing') && $expression !== 'send($event)') {
                    $unguarded[] = basename($file->getPathname()).': '.$expression;
                }
            }
        }

        $this->assertSame([], $unguarded);
    }

    public function test_the_page_of_a_list_renders_the_guard_of_the_quick_add_field(): void
    {
        $project = Project::factory()->create();
        $editor = User::factory()->create();
        $project->setRole($editor, ProjectRole::Editor);

        Livewire::actingAs($editor)->test('pages::projects.show', ['project' => $project])
            ->assertSeeHtml('x-on:keydown.enter.prevent="if (! $composing($event)) { add() }"');
    }
}
