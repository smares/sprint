{{-- The unicorn that now and then appears when a task is completed (the server decides, see CelebrationService): it fades in in the
     middle of the screen, flies diagonally up to the right and fades out again, without covering or blocking anything. --}}
<div
    x-data="{
        flying: false,
        fly() {
            if (this.flying || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return
            }

            this.flying = true
            setTimeout(() => this.flying = false, 3000)
        },
    }"
    x-on:task-completed.window="fly()"
    class="pointer-events-none fixed inset-0 z-50 overflow-hidden"
    aria-hidden="true"
>
    <template x-if="flying">
        <img src="{{ asset('unicorn.svg') }}" alt="" class="unicorn-flight absolute start-1/2 top-1/2 h-32 w-auto select-none" draggable="false">
    </template>
</div>
