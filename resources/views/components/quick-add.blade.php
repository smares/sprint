@props(['statusId' => null])

{{-- Adds a task by typing its title: Enter creates it and keeps the field for the next one, Esc or an empty field closes it. The page provides quickAdd(title, statusId). --}}
<div
    x-data="{
        adding: false,
        title: '',
        open() {
            this.adding = true
            // Shown and focused right away, not on the next tick: otherwise the first key typed after the click is lost
            this.$refs.field.style.display = ''
            this.$refs.title.focus()
        },
        close() {
            this.adding = false
            this.title = ''
        },
        add() {
            const title = this.title.trim()

            if (title === '') {
                return
            }

            this.title = ''
            $wire.quickAdd(title, @js($statusId))
        },
    }"
    {{ $attributes }}
>
    <flux:button x-show="! adding" size="sm" variant="subtle" icon="plus" class="w-full justify-start" x-on:click="open()">{{ __('Add task') }}</flux:button>

    <div x-show="adding" x-ref="field" x-cloak>
        <flux:input
            x-ref="title"
            x-model="title"
            size="sm"
            maxlength="255"
            :placeholder="__('Task title, then Enter')"
            :aria-label="__('New task')"
            x-on:keydown.enter.prevent="add()"
            x-on:keydown.escape.prevent.stop="close()"
            x-on:blur="if (title.trim() === '') { close() }"
        />
    </div>
</div>
