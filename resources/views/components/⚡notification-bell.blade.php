<?php

use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function unread(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }
};
?>

<div wire:poll.60s>
    <flux:button href="{{ route('inbox') }}" wire:navigate variant="ghost" icon="bell" aria-label="Posteingang{{ $this->unread > 0 ? ', '.$this->unread.' ungelesen' : '' }}" class="relative">
        @if ($this->unread > 0)
            <span class="absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-4 text-white">{{ $this->unread > 99 ? '99+' : $this->unread }}</span>
        @endif
    </flux:button>
</div>
