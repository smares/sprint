@props(['tasks', 'childrenMap', 'depth' => 0])

<ul @class(['space-y-2', 'ms-6 mt-2 border-s border-zinc-200 ps-4 dark:border-zinc-700' => $depth > 0])>
    @foreach ($tasks as $subtask)
        @php($children = $childrenMap->get($subtask->id, collect()))
        <li wire:key="subtask-{{ $subtask->id }}">
            <div class="flex items-center gap-3">
                <flux:checkbox :checked="$subtask->status === \App\TaskStatus::Done" wire:click="toggleSubtask({{ $subtask->id }})" />
                <a href="{{ route('tasks.show', $subtask) }}" wire:navigate @class(['hover:underline', 'line-through text-zinc-400' => $subtask->status === \App\TaskStatus::Done])>{{ $subtask->title }}</a>
                @if ($subtask->assignee)
                    <flux:text size="sm">{{ $subtask->assignee->name }}</flux:text>
                @endif
            </div>

            @if ($children->isNotEmpty())
                <x-task-subtree :tasks="$children" :children-map="$childrenMap" :depth="$depth + 1" />
            @endif

            <form wire:submit="addSubtask({{ $subtask->id }})" class="ms-8 mt-1 flex items-center gap-2">
                <flux:input size="sm" wire:model="newSubtaskTitles.{{ $subtask->id }}" placeholder="Subtask zu „{{ \Illuminate\Support\Str::limit($subtask->title, 30) }}“ …" class="max-w-xs" />
            </form>
        </li>
    @endforeach
</ul>
