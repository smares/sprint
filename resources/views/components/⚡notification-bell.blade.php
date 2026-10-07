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

<div wire:poll.60s class="relative">
    <flux:button href="{{ route('inbox') }}" wire:navigate variant="ghost" icon="bell" aria-label="{{ $this->unread > 0 ? __('Inbox, :count unread', ['count' => $this->unread]) : __('Inbox') }}" />

    @if ($this->unread > 0)
        <span aria-hidden="true" class="pointer-events-none absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-4 text-white">{{ $this->unread > 99 ? '99+' : $this->unread }}</span>
    @endif
</div>
