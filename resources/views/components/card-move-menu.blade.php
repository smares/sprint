{{-- The menu that moves a card of the board "My tasks" without dragging it (for the keyboard, switches and fingers that do
     not drag): one per board, shown at the button of the card that was clicked (data-card-menu, see cardMenu in app.js),
     so the columns are in the page once and not once for every card. The board provides moveTask(taskId, position, column)
     and the columns as data-move-targets on the element around the cards. wire:ignore keeps the menu open and focused while
     the board renders again. --}}
<div
    data-card-menu-list
    wire:ignore
    x-data="cardMenu"
    x-on:open-card-menu.window="show($event.detail.anchor)"
    x-on:keydown.escape.window="close(true)"
>
    <div
        x-ref="menu"
        x-show="isOpen"
        x-cloak
        x-anchor.bottom-end.offset.4="anchor"
        x-on:click.outside="close()"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.tab="close(true)"
        role="menu"
        aria-label="{{ __('Move task') }}"
        class="z-50 max-h-80 w-56 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-600 dark:bg-zinc-700"
    >
        <div role="group" aria-labelledby="card-menu-heading">
            <div id="card-menu-heading" class="px-2 py-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Move to column') }}</div>
            <template x-for="target in columns" x-bind:key="target.id">
                <x-menu-button x-on:click="pick(target.id)">
                    <span class="min-w-0 flex-1 truncate" x-text="target.name"></span>
                </x-menu-button>
            </template>
        </div>

        <x-menu-separator x-show="canMoveUp || canMoveDown" />

        <x-menu-button x-show="canMoveUp" x-on:click="pick(column, index - 1)">
            <flux:icon.arrow-up variant="micro" class="text-zinc-400" />
            {{ __('Move up') }}
        </x-menu-button>
        <x-menu-button x-show="canMoveDown" x-on:click="pick(column, index + 1)">
            <flux:icon.arrow-down variant="micro" class="text-zinc-400" />
            {{ __('Move down') }}
        </x-menu-button>
    </div>
</div>
