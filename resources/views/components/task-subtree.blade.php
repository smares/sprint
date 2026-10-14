@props(['tasks', 'childrenMap', 'parentId', 'depth' => 0, 'canEdit' => false, 'panel' => false])

<ul
    {!! $canEdit ? 'wire:sort="moveSubtask" wire:sort:group="subtasks"' : '' !!}
    wire:sort:group-id="{{ $parentId }}"
    wire:sort:config="{{ \App\Sortable::CONFIG }}"
    @class(['space-y-2', 'ms-6 mt-2 border-s border-zinc-200 ps-4 dark:border-zinc-700' => $depth > 0])
>
    @foreach ($tasks as $subtask)
        @if ($subtask->is_section)
            <li wire:key="section-{{ $subtask->id }}" wire:sort:item="{{ $subtask->id }}" class="flex items-center gap-2 border-b border-zinc-200 pb-1 pt-3 dark:border-zinc-700">
                @if ($canEdit)
                    <flux:input size="sm" variant="filled" class="max-w-xs font-semibold" wire:model.live.blur="sectionTitles.{{ $subtask->id }}" aria-label="{{ __('Heading') }}" />
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteSection({{ $subtask->id }})" aria-label="{{ __('Delete heading') }}" tooltip="{{ __('Delete heading') }}" />
                @else
                    <flux:heading size="sm">{{ $subtask->title }}</flux:heading>
                @endif
            </li>
        @else
            @php($children = $childrenMap->get($subtask->id, collect()))
            <li wire:key="subtask-{{ $subtask->id }}" wire:sort:item="{{ $subtask->id }}">
                <div class="flex items-center gap-2">
                    <x-task-done-button :task="$subtask" :editable="$canEdit" action="toggleSubtask" />
                    @if ($panel)
                        <button type="button" x-on:click="$dispatch('open-task', { id: {{ $subtask->id }} })" @class(['text-start hover:underline', 'line-through text-zinc-400' => $subtask->isDone()])>{{ $subtask->title }}</button>
                    @else
                        <a href="{{ route('tasks.show', $subtask) }}" wire:navigate @class(['hover:underline', 'line-through text-zinc-400' => $subtask->isDone()])>{{ $subtask->title }}</a>
                    @endif
                    {{-- Status, person and due date like in the lists, but quieter: the status only when it says more than open or done --}}
                    <div class="ms-auto flex shrink-0 items-center gap-2">
                        @if (! $subtask->isDone() && $subtask->status && $subtask->status->position > 0)
                            <x-status-badge :status="$subtask->status" class="max-sm:hidden" />
                        @endif
                        <x-task-due :task="$subtask" />
                        @if ($subtask->assignee)
                            <x-user-avatar size="xs" :user="$subtask->assignee" :tooltip="$subtask->assignee->labelledName()" />
                        @endif
                    </div>
                </div>

                @if ($children->isNotEmpty())
                    <x-task-subtree :tasks="$children" :children-map="$childrenMap" :parent-id="$subtask->id" :depth="$depth + 1" :can-edit="$canEdit" :panel="$panel" />
                @endif
            </li>
        @endif
    @endforeach
</ul>
