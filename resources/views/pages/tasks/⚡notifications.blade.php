<?php

use App\Models\Task;
use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Benachrichtigungen')] class extends Component
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
    <flux:heading size="xl">Benachrichtigungen</flux:heading>

    @if ($muted)
        <flux:callout variant="success" icon="bell-slash" heading="Abbestellt" text="Du bekommst keine E-Mails mehr zu „{{ $task->title }}“." />
        <flux:button wire:click="unmute" icon="bell">Wieder aktivieren</flux:button>
    @else
        <flux:text>Möchtest du keine E-Mails mehr zu „{{ $task->title }}“ ({{ $task->project->name }}) bekommen, {{ $user->name }}?</flux:text>
        <flux:button wire:click="mute" icon="bell-slash" variant="primary">Für diese Aufgabe abbestellen</flux:button>
    @endif
</div>
