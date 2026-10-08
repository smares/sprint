@props(['label' => null, 'rows' => 4, 'placeholder' => null, 'mentions' => ['users' => [], 'searchTasks' => false], 'images' => null])

<div
    x-data="{
        tab: 'write', html: '', loading: false, imageMenu: false, uploading: false,
        insertImage(id, name) {
            const field = this.$root.querySelector('textarea')
            const token = '![' + String(name).replace(/[\[\]\n]/g, '') + '](attachment:' + id + ')'
            const start = field.selectionStart ?? field.value.length
            const end = field.selectionEnd ?? start

            field.value = field.value.slice(0, start) + token + field.value.slice(end)
            field.selectionStart = field.selectionEnd = start + token.length
            field.dispatchEvent(new Event('input', { bubbles: true }))
            field.focus()
            this.imageMenu = false
        },
        uploadImage(file) {
            if (! file || ! file.type.startsWith('image/')) {
                return
            }

            this.uploading = true
            this.imageMenu = false

            $wire.upload(
                'inlineUpload',
                file,
                () => $wire.storeInlineImage().then((image) => { if (image) { this.insertImage(image.id, image.name) } }).finally(() => { this.uploading = false }),
                () => { this.uploading = false },
            )
        },
        onPaste(event) {
            const file = [...(event.clipboardData?.files ?? [])].find((item) => item.type.startsWith('image/'))

            if (file) {
                event.preventDefault()
                this.uploadImage(file)
            }
        },
        onDrop(event) {
            const file = [...(event.dataTransfer?.files ?? [])].find((item) => item.type.startsWith('image/'))

            if (file) {
                event.preventDefault()
                this.uploadImage(file)
            }
        },
    }"
    class="space-y-2"
>
    <div class="flex items-center justify-between">
        @if ($label)
            <flux:label>{{ $label }}</flux:label>
        @endif

        <div class="flex items-center gap-2 text-sm">
            @if ($images !== null)
                <div class="relative" x-on:click.outside="imageMenu = false" x-show="tab === 'write'">
                    <button type="button" class="flex items-center gap-1 text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200" x-on:click="imageMenu = ! imageMenu" aria-label="{{ __('Insert image') }}" title="{{ __('Insert image') }}">
                        <flux:icon.photo variant="micro" /><span x-show="uploading" x-cloak>{{ __('Uploading …') }}</span>
                    </button>
                    <div x-show="imageMenu" x-cloak class="absolute end-0 z-20 mt-1 max-h-64 w-64 overflow-auto rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                        @foreach ($images as $image)
                            <button type="button" class="block w-full truncate rounded px-2 py-1 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="insertImage({{ $image['id'] }}, @js($image['name']))">{{ $image['name'] }}</button>
                        @endforeach
                        <label class="block w-full cursor-pointer rounded px-2 py-1 text-start text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700">
                            {{ __('Upload image …') }}
                            <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="hidden" x-on:change="uploadImage($event.target.files[0]); $event.target.value = ''">
                        </label>
                    </div>
                </div>
            @endif
            <div class="flex gap-1">
            <button type="button" x-on:click="tab = 'write'" x-bind:class="tab === 'write' ? 'font-semibold underline' : 'text-zinc-500'">{{ __('Write') }}</button>
            <span class="text-zinc-300">|</span>
            <button
                type="button"
                x-on:click="tab = 'preview'; loading = true; $wire.previewMarkdown($root.querySelector('textarea').value).then(result => { html = result; loading = false })"
                x-bind:class="tab === 'preview' ? 'font-semibold underline' : 'text-zinc-500'"
            >{{ __('Preview') }}</button>
            </div>
        </div>
    </div>

    <div x-show="tab === 'write'" class="relative" x-data="mentionable(@js($mentions))" x-on:input="onInput($event)" x-on:keydown="onKeydown($event)" x-on:click.outside="open = false"@if ($images !== null) x-on:paste="onPaste($event)" x-on:drop="onDrop($event)" x-on:dragover.prevent @endif>
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
