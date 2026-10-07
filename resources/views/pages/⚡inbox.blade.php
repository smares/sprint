<?php

use App\Models\Task;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Inbox')] class extends Component
{
    use WithPagination;

    #[Computed]
    public function notifications()
    {
        return auth()->user()->notifications()->paginate(30);
    }

    /**
     * Tasks the person may still open, keyed by id.
     */
    #[Computed]
    public function tasks()
    {
        $ids = $this->notifications->getCollection()->pluck('data.task_id')->filter()->unique();

        return Task::whereIn('id', $ids)
            ->whereHas('project', fn ($projects) => $projects->visibleTo(auth()->user()))
            ->with('project')
            ->get()
            ->keyBy('id');
    }

    private function ownNotification(string $id): DatabaseNotification
    {
        return auth()->user()->notifications()->findOrFail($id);
    }

    public function open(string $id): void
    {
        $notification = $this->ownNotification($id);
        $notification->markAsRead();

        $task = $this->tasks->get($notification->data['task_id'] ?? null);

        if ($task === null) {
            unset($this->notifications);

            return;
        }

        $this->redirectRoute('tasks.show', $task, navigate: true);
    }

    public function toggleRead(string $id): void
    {
        $notification = $this->ownNotification($id);
        $notification->read_at === null ? $notification->markAsRead() : $notification->markAsUnread();

        unset($this->notifications);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);

        unset($this->notifications);
    }

    public function remove(string $id): void
    {
        $this->ownNotification($id)->delete();

        unset($this->notifications);
    }
};
?>

<div class="max-w-3xl">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Inbox') }}</flux:heading>
        @if (auth()->user()->unreadNotifications()->exists())
            <flux:button size="sm" icon="check" wire:click="markAllRead">{{ __('Mark all as read') }}</flux:button>
        @endif
    </div>

    @if ($this->notifications->isEmpty())
        <flux:callout icon="inbox" :heading="__('Nothing new')" :text="__('Comments, status changes and mentions on tasks you are responsible for or involved in appear here.')" />
    @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->notifications as $notification)
                @php($task = $this->tasks->get($notification->data['task_id'] ?? null))
                <li wire:key="notification-{{ $notification->id }}" class="flex items-start gap-3 py-3">
                    <span class="mt-2 size-2 shrink-0 rounded-full {{ $notification->read_at === null ? 'bg-blue-500' : 'bg-transparent' }}" aria-label="{{ $notification->read_at === null ? __('unread') : __('read') }}"></span>
                    <div class="min-w-0 flex-1">
                        @if ($task)
                            <button type="button" wire:click="open('{{ $notification->id }}')" class="block truncate text-start font-medium hover:underline">{{ $task->title }}</button>
                            <flux:text size="sm">{{ \App\InboxText::sentence($notification) }} · {{ $task->project->name }} · {{ $notification->created_at->diffForHumans() }}</flux:text>
                        @else
                            <flux:text class="italic">{{ __('Task no longer available') }}</flux:text>
                            <flux:text size="sm">{{ $notification->created_at->diffForHumans() }}</flux:text>
                        @endif
                    </div>
                    <flux:button size="xs" variant="ghost" :icon="$notification->read_at === null ? 'envelope-open' : 'envelope'" wire:click="toggleRead('{{ $notification->id }}')" aria-label="{{ $notification->read_at === null ? __('Mark as read') : __('Mark as unread') }}" />
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove('{{ $notification->id }}')" aria-label="{{ __('Remove') }}" />
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $this->notifications->links() }}</div>
    @endif
</div>
