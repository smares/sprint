<?php

use App\Services\RealtimeService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** `sidebar`: the inbox entry of the sidebar with its count; `button`: the bell in the phone header. */
    public string $variant = 'button';

    /**
     * Whether new entries are announced with a toast. Both entries are on the page on phones, so only one of them says so.
     */
    public bool $announces = true;

    /** Whether the page is the inbox, remembered from the page request (later requests are Livewire's own). */
    #[Locked]
    public bool $onInbox = false;

    public function mount(): void
    {
        $this->onInbox = request()->routeIs('inbox');
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return app(RealtimeService::class)->enabled()
            ? ['echo-private:user.'.auth()->id().',.InboxUpdated' => 'inboxUpdated']
            : [];
    }

    /**
     * The inbox page read, unread or removed entries: the bell stays on the page across navigation, so it is told.
     */
    #[On('inbox-changed')]
    public function inboxChanged(): void
    {
        unset($this->unread);
    }

    public function inboxUpdated(): void
    {
        unset($this->unread);

        if ($this->announces) {
            Flux::toast(text: __('There is something new in your inbox.'));
        }
    }

    #[Computed]
    public function unread(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }
};
?>

{{-- With live updates the count arrives over the socket; without them it is fetched every minute. --}}
<div @unless (app(RealtimeService::class)->enabled()) wire:poll.60s @endunless class="relative">
    @if ($variant === 'sidebar')
        <flux:sidebar.item icon="inbox" :href="route('inbox')" wire:navigate :current="$onInbox" :badge="$this->unread > 0 ? ($this->unread > 99 ? '99+' : (string) $this->unread) : null" badge:color="red" :aria-label="$this->unread > 0 ? __('Inbox, :count unread', ['count' => $this->unread]) : __('Inbox')">{{ __('Inbox') }}</flux:sidebar.item>
        {{-- Collapsed, only the icon shows: a dot tells that something is unread --}}
        @if ($this->unread > 0)
            <span aria-hidden="true" class="pointer-events-none absolute start-7 top-1.5 hidden size-2 rounded-full bg-red-500 in-data-flux-sidebar-collapsed-desktop:block"></span>
        @endif
    @else
        <flux:button href="{{ route('inbox') }}" wire:navigate variant="ghost" icon="bell" aria-label="{{ $this->unread > 0 ? __('Inbox, :count unread', ['count' => $this->unread]) : __('Inbox') }}" tooltip="{{ $this->unread > 0 ? __('Inbox, :count unread', ['count' => $this->unread]) : __('Inbox') }}" />

        @if ($this->unread > 0)
            <span aria-hidden="true" class="pointer-events-none absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-4 text-white">{{ $this->unread > 99 ? '99+' : $this->unread }}</span>
        @endif
    @endif
</div>
