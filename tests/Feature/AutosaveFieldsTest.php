<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Since Livewire 4.1, wire:model.blur, .change and .enter only update the value in the browser. Fields that save on
 * their own need .live as well, or nothing reaches the server until some other action sends a request.
 */
class AutosaveFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_field_waits_for_blur_change_or_enter_without_sending_it(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/wire:model((?:\.[a-z0-9]+)*)=/', $file->getContents(), $matches);

            foreach ($matches[1] as $modifiers) {
                $modifiers = explode('.', ltrim($modifiers, '.'));

                if (array_intersect($modifiers, ['blur', 'change', 'enter']) !== [] && ! in_array('live', $modifiers, true)) {
                    $offenders[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'wire:model with .blur, .change or .enter but without .live never sends the value');
    }

    public function test_renaming_a_team_is_sent_when_the_field_loses_the_focus(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $team = Team::create(['name' => 'Design']);

        Livewire::test('pages::admin.teams')
            ->assertSeeHtml('wire:model.live.blur="names.'.$team->id.'"')
            ->set("names.{$team->id}", 'Gestaltung');

        $this->assertSame('Gestaltung', $team->fresh()->name);
    }
}
