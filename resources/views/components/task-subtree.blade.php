@props(['tasks', 'childrenMap', 'parentId', 'depth' => 0, 'canEdit' => false, 'panel' => false])

<ul
    {!! $canEdit ? 'wire:sort="moveSubtask" wire:sort:group="subtasks"' : '' !!}
    wire:sort:group-id="{{ $parentId }}"
    wire:sort:config="{ delay: 250, delayOnTouchOnly: true, touchStartThreshold: 12 }"
    @class(['space-y-2', 'ms-6 mt-2 border-s border-zinc-200 ps-4 dark:border-zinc-700' => $depth > 0])
>
    @foreach ($tasks as $subtask)
        @if ($subtask->is_section)
            <li wire:key="section-{{ $subtask->id }}" wire:sort:item="{{ $subtask->id }}" class="flex items-center gap-2 border-b border-zinc-200 pb-1 pt-3 dark:border-zinc-700">
                @if ($canEdit)
                    <flux:input size="sm" variant="filled" class="max-w-xs font-semibold" wire:model.live.blur="sectionTitles.{{ $subtask->id }}" aria-label="{{ __('Heading') }}" />
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteSection({{ $subtask->id }})" aria-label="{{ __('Delete heading') }}" />
                @else
                    <flux:heading size="sm">{{ $subtask->title }}</flux:heading>
                @endif
            </li>
        @else
            @php($children = $childrenMap->get($subtask->id, collect()))
            <li wire:key="subtask-{{ $subtask->id }}" wire:sort:item="{{ $subtask->id }}">
                <div class="flex items-center gap-3">
                    <flux:checkbox :checked="$subtask->isDone()" :disabled="! $canEdit" wire:click="toggleSubtask({{ $subtask->id }})" />
                    @if ($panel)
                        <button type="button" x-on:click="$dispatch('open-task', { id: {{ $subtask->id }} })" @class(['text-start hover:underline', 'line-through text-zinc-400' => $subtask->isDone()])>{{ $subtask->title }}</button>
                    @else
                        <a href="{{ route('tasks.show', $subtask) }}" wire:navigate @class(['hover:underline', 'line-through text-zinc-400' => $subtask->isDone()])>{{ $subtask->title }}</a>
                    @endif
                    @if ($subtask->assignee)
                        <flux:text size="sm">{{ $subtask->assignee->labelledName() }}</flux:text>
                    @endif
                </div>

                @if ($children->isNotEmpty())
                    <x-task-subtree :tasks="$children" :children-map="$childrenMap" :parent-id="$subtask->id" :depth="$depth + 1" :can-edit="$canEdit" :panel="$panel" />
                @endif

                @if ($canEdit)
                    <form wire:submit="addSubtask({{ $subtask->id }})" class="ms-8 mt-1 flex items-center gap-2">
                        <flux:input size="sm" wire:model="newSubtaskTitles.{{ $subtask->id }}" placeholder="{{ __('Subtask for “:title” …', ['title' => \Illuminate\Support\Str::limit($subtask->title, 30)]) }}" class="max-w-xs" />
                        <flux:button type="button" size="xs" variant="ghost" icon="bars-3-bottom-left" wire:click="addSection({{ $subtask->id }})" aria-label="{{ __('Add heading') }}" />
                    </form>
                @endif
            </li>
        @endif
    @endforeach
</ul>
