{{-- The unicorn that now and then flies across the screen when a task is completed (the server decides, see CelebrationService). --}}
<div
    x-data="{
        flying: false,
        fly() {
            if (this.flying || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return
            }

            this.flying = true
            setTimeout(() => this.flying = false, 4200)
        },
    }"
    x-on:task-completed.window="fly()"
    class="pointer-events-none fixed inset-x-0 top-1/4 z-50 h-48 overflow-hidden"
    aria-hidden="true"
>
    <template x-if="flying">
        <img src="{{ asset('unicorn.svg') }}" alt="" class="unicorn-flight absolute start-0 top-8 h-32 w-auto select-none" draggable="false">
    </template>
</div>
