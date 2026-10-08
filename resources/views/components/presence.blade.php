@props(['channel' => null, 'one' => '', 'many' => null])

{{-- Who else is looking at the page, kept entirely in the browser: joining and leaving never cost a request. --}}
@if ($channel !== null && app(\App\Services\RealtimeService::class)->clientConfig() !== null)
    <div
        wire:ignore
        x-data="presence(@js($channel), @js(auth()->id()), @js(['one' => $one, 'many' => $many]))"
        x-show="users.length"
        x-cloak
        {{ $attributes->class('flex items-center gap-2') }}
        aria-live="polite"
    >
        <div class="flex -space-x-2">
            <template x-for="user in users.slice(0, 5)" x-bind:key="user.id">
                <span
                    class="inline-flex size-6 items-center justify-center overflow-hidden rounded-full bg-zinc-200 text-[0.625rem] font-medium text-zinc-800 ring-2 ring-white dark:bg-zinc-600 dark:text-white dark:ring-zinc-900"
                    x-bind:title="user.name"
                >
                    <template x-if="user.avatar"><img x-bind:src="user.avatar" alt="" class="size-full object-cover"></template>
                    <template x-if="! user.avatar"><span x-text="user.initials"></span></template>
                </span>
            </template>
        </div>

        <span class="text-sm text-zinc-500 dark:text-zinc-400" x-show="label()" x-text="label()"></span>
    </div>
@endif
