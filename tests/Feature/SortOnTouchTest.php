<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * On phones a tap must open a task and a swipe must scroll: dragging starts only after holding a finger still for a
 * moment (SortableJS delay on touch, with a few pixels of leeway, since a still finger still trembles).
 */
class SortOnTouchTest extends TestCase
{
    private const string TOUCH_CONFIG = 'wire:sort:config="{ delay: 250, delayOnTouchOnly: true, touchStartThreshold: 12 }"';

    public function test_every_sortable_list_waits_for_a_long_press_on_touch_screens(): void
    {
        $missing = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $contents = $file->getContents();
            $sortables = preg_match_all('/(?<![\w-]):?wire:sort=/', $contents);

            if ($sortables > substr_count($contents, self::TOUCH_CONFIG)) {
                $missing[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $missing, 'Lists with wire:sort but without the touch delay start dragging on every tap');
    }
}
