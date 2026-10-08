<?php

namespace App\Services;

/**
 * Decides whether completing a task is worth a unicorn. `Task` reports each completion; a Livewire hook
 * (see AppServiceProvider) asks after every action whether to tell the browser. At most one per action,
 * rarely (`sprint.celebration_chance`), never for people who turned it off, and not for what a rule does.
 */
class CelebrationService
{
    private bool $due = false;

    public function taskCompleted(): void
    {
        $user = auth()->user();

        if ($this->due || $user === null || ! $user->celebrations_enabled || app(AutomationService::class)->running() !== null) {
            return;
        }

        $this->due = random_int(0, 9999) < (int) round((float) config('sprint.celebration_chance') * 10000);
    }

    /**
     * Whether a unicorn is due; asking resets it.
     */
    public function consume(): bool
    {
        $due = $this->due;
        $this->due = false;

        return $due;
    }
}
