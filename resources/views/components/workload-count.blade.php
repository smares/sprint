@props(['group', 'overdue' => false])

{{-- A number on the workload page: it opens a menu with the first tasks behind it (each a link) and how many more there
     are, by click, tap or keyboard alike; nothing to open for none. --}}
@if ($group['count'] === 0)
    <span class="text-zinc-300 dark:text-zinc-600">–</span>
@else
    <flux:dropdown position="bottom" align="end">
        <flux:button size="sm" variant="ghost" @class(['tabular-nums font-medium', 'text-red-600! dark:text-red-400!' => $overdue])
            :aria-label="trans_choice(':count task|:count tasks', $group['count'])">{{ $group['count'] }}</flux:button>

        <flux:menu class="max-w-72">
            @foreach ($group['tasks'] as $task)
                <flux:menu.item :href="route('tasks.show', $task['id'])" wire:navigate wire:key="workload-task-{{ $task['id'] }}" class="truncate">{{ $task['title'] }}</flux:menu.item>
            @endforeach
            @if ($group['count'] > count($group['tasks']))
                <flux:menu.separator />
                <flux:text size="sm" class="px-2 py-1.5">{{ trans_choice('and :count more|and :count more', $group['count'] - count($group['tasks'])) }}</flux:text>
            @endif
        </flux:menu>
    </flux:dropdown>
@endif
