@props(['label' => null, 'rows' => 4, 'placeholder' => null, 'mentions' => ['users' => [], 'tasks' => []]])

<div x-data="{ tab: 'write', html: '', loading: false }" class="space-y-2">
    <div class="flex items-center justify-between">
        @if ($label)
            <flux:label>{{ $label }}</flux:label>
        @endif

        <div class="flex gap-1 text-sm">
            <button type="button" x-on:click="tab = 'write'" x-bind:class="tab === 'write' ? 'font-semibold underline' : 'text-zinc-500'">{{ __('Write') }}</button>
            <span class="text-zinc-300">|</span>
            <button
                type="button"
                x-on:click="tab = 'preview'; loading = true; $wire.previewMarkdown($root.querySelector('textarea').value).then(result => { html = result; loading = false })"
                x-bind:class="tab === 'preview' ? 'font-semibold underline' : 'text-zinc-500'"
            >{{ __('Preview') }}</button>
        </div>
    </div>

    <div x-show="tab === 'write'" class="relative" x-data="mentionable(@js($mentions))" x-on:input="onInput($event)" x-on:keydown="onKeydown($event)" x-on:click.outside="open = false">
        <flux:textarea :rows="$rows" :placeholder="$placeholder" {{ $attributes->whereStartsWith('wire:model') }} />

        <ul x-show="open && matches.length" x-cloak class="absolute z-20 mt-1 max-h-64 w-72 overflow-auto rounded-lg border border-zinc-200 bg-white p-1 text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-800" role="listbox">
            <template x-for="(item, index) in matches" x-bind:key="item.type + item.id">
                <li>
                    <div x-show="index === 0 || matches[index - 1].type !== item.type" class="px-2 pt-1 text-xs font-semibold uppercase text-zinc-400" x-text="item.type === 'user' ? @js(__('People')) : @js(__('Tasks'))"></div>
                    <button
                        type="button"
                        role="option"
                        class="block w-full truncate rounded px-2 py-1 text-start"
                        x-bind:class="index === active ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
                        x-on:mousedown.prevent="select(item)"
                        x-text="item.label"
                    ></button>
                </li>
            </template>
        </ul>
    </div>

    <div x-show="tab === 'preview'" x-cloak class="min-h-24 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
        <flux:text x-show="loading">{{ __('Loading...') }}</flux:text>
        <div x-show="!loading" class="markdown" x-html="html || '<em>' + @js(__('Nothing to show.')) + '</em>'"></div>
    </div>
</div>
