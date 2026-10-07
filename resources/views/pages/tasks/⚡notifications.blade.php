<?php

use App\Models\Task;
use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component
{
    public Task $task;

    public User $user;

    public bool $muted = false;

    public function mount(): void
    {
        $this->muted = $this->task->isMutedBy($this->user);
    }

    public function mute(): void
    {
        $this->task->setMutedBy($this->user, true);
        $this->muted = true;
    }

    public function unmute(): void
    {
        $this->task->setMutedBy($this->user, false);
        $this->muted = false;
    }
};
?>

<div class="mx-auto max-w-md space-y-4 pt-12">
    <flux:heading size="xl">{{ __('Notifications') }}</flux:heading>

    @if ($muted)
        <flux:callout variant="success" icon="bell-slash" :heading="__('Unsubscribed')" :text="__('You will no longer receive emails about “:task”.', ['task' => $task->title])" />
        <flux:button wire:click="unmute" icon="bell">{{ __('Reactivate') }}</flux:button>
    @else
        <flux:text>{{ __('Do you want to stop receiving emails about “:task” (:project), :name?', ['task' => $task->title, 'project' => $task->project->name, 'name' => $user->name]) }}</flux:text>
        <flux:button wire:click="mute" icon="bell-slash" variant="primary">{{ __('Unsubscribe from this task') }}</flux:button>
    @endif
</div>
