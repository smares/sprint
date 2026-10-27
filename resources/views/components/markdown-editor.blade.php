@props(['label' => null, 'rows' => 4, 'placeholder' => null, 'mentions' => ['users' => [], 'searchTasks' => false], 'mentionsOnDemand' => false, 'images' => null, 'grow' => false, 'max' => null, 'preview' => false, 'previewHtml' => null])

{{--
    max: the longest text, shown as a counter while writing. It counts Unicode code points ([...text].length), which is how
    Laravel's max rule counts (mb_strlen): not UTF-16 units (an emoji would be two) and not bytes. An emoji made of several
    parts (family, flag, skin tone) and a letter with a combining accent count for each part, on the page as in the check.
    mentionsOnDemand: the people after @ are not part of the page: the editor asks its component for them (mentionPeople, see
        EditsMarkdown) when it is focused or the first @ is typed. Tasks are looked up on the server while typing in any case.
    preview: the text starts rendered (previewHtml comes with the page) and a click on "Edit" opens the field.
--}}

<div
    x-data="{
        tab: @js($preview ? 'preview' : 'write'), html: @js((string) $previewHtml), loading: false, imageMenu: false, uploading: false,
        max: @js($max), count: 0,
        size() {
            const field = this.$root.querySelector('textarea')

            return field ? [...field.value].length : 0
        },
        showPreview() {
            this.tab = 'preview'
            this.loading = true
            $wire.previewMarkdown(this.$root.querySelector('textarea').value)
                .then((result) => { this.html = result })
                // Without an answer the text cannot be shown rendered: back to the field, where nothing is lost
                .catch(() => { this.tab = 'write' })
                .finally(() => { this.loading = false })
        },
        edit() {
            this.tab = 'write'
            this.count = this.size()
            // The caret goes to the start and the page stays where it is: a long text would otherwise scroll to its end
            this.$nextTick(() => {
                const field = this.$root.querySelector('textarea')

                field.setSelectionRange(0, 0)
                field.focus({ preventScroll: true })
            })
        },
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
            const file = @js($images !== null) ? [...(event.clipboardData?.files ?? [])].find((item) => item.type.startsWith('image/')) : null

            if (file) {
                event.preventDefault()
                this.uploadImage(file)

                return
            }

            this.linkPastedTask(event)
        },
        // The address of a task of this installation (the page of the task, or a list with ?task=) that is pasted stays as pasted
        // at first and becomes @[Title](task:7) once the server has told the title: a task that is gone or that the person may not
        // see stays an address (resolveTaskLink). Typing goes on meanwhile, so the answer looks for the address again.
        linkPastedTask(event) {
            const field = event.target
            const address = (event.clipboardData?.getData('text/plain') ?? '').trim()

            if (field.tagName !== 'TEXTAREA' || field.selectionStart !== field.selectionEnd || ! this.isTaskAddress(address)) {
                return
            }

            const near = field.selectionStart

            $wire.resolveTaskLink(address)
                .then((task) => task && this.replaceAddress(field, address, near, task))
                .catch(() => {})
        },
        isTaskAddress(address) {
            try {
                const url = new URL(address)

                return /^https?:$/.test(url.protocol) && url.origin === window.location.origin
                    && (/^\/tasks\/\d+\/?$/.test(url.pathname) || /^\d+$/.test(url.searchParams.getAll('task').at(-1) ?? ''))
            } catch {
                return false
            }
        },
        // Only an address that still stands alone (between spaces or line ends, so not one the person has changed or continued) and
        // the one nearest to where it was pasted: it may have moved, been deleted, or been pasted again
        replaceAddress(field, address, near, task) {
            if (! field.isConnected) {
                return
            }

            const text = field.value
            let found = -1

            for (let at = text.indexOf(address); at !== -1; at = text.indexOf(address, at + 1)) {
                const alone = (at === 0 || /\s/.test(text[at - 1])) && (at + address.length === text.length || /\s/.test(text[at + address.length]))

                if (alone && (found === -1 || Math.abs(at - near) < Math.abs(found - near))) {
                    found = at
                }
            }

            if (found === -1) {
                return
            }

            // As the list after @ writes it: brackets become parentheses, and a title of only blanks has no name to show
            const title = [...String(task.title).replace(/\[/g, '(').replace(/\]/g, ')').replace(/\s+/g, ' ').trim()].slice(0, 255).join('')
            const token = '@[' + (title || '#' + task.id) + '](task:' + task.id + ')'
            const end = found + address.length
            const [from, to] = [field.selectionStart, field.selectionEnd].map((caret) => (caret >= end ? caret + token.length - address.length : (caret > found ? found + token.length : caret)))

            // Typed into the focused field, the replacement stays in its undo history (Ctrl+Z gives the address back); in a field
            // that is not focused (the person went elsewhere meanwhile) the text is set directly
            field.setSelectionRange(found, end)

            if (document.activeElement !== field || ! document.execCommand('insertText', false, token)) {
                field.setRangeText(token, found, end, 'preserve')
                field.dispatchEvent(new Event('input', { bubbles: true }))
            }

            field.setSelectionRange(from, to)
        },
        onDrop(event) {
            const file = [...(event.dataTransfer?.files ?? [])].find((item) => item.type.startsWith('image/'))

            if (file) {
                event.preventDefault()
                this.uploadImage(file)
            }
        },
    }"
    {{-- The "New task" dialog stays on the page: after creating, the next task starts on Write, not on the old preview --}}
    @unless ($preview)
        x-on:task-created.window="tab = 'write'; html = ''"
    @endunless
    @if ($max !== null)
        x-init="$nextTick(() => { count = size() }); $wire.$watch(@js($attributes->wire('model')->value()), () => { count = size() })"
        x-on:input="count = size()"
    @endif
    @if ($preview)
        {{-- Saved: back to the rendered text --}}
        x-on:task-saved.window="showPreview()"
    @endif
    class="space-y-2"
>
    <div class="flex items-center justify-between">
        @if ($label)
            <flux:label>{{ $label }}</flux:label>
        @endif

        <div class="flex items-center gap-2 text-sm">
            @if ($images !== null)
                <div class="relative" x-on:click.outside="imageMenu = false" x-show="tab === 'write'">
                    <button type="button" class="touch-target flex items-center gap-1 text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200" x-on:click="imageMenu = ! imageMenu" aria-label="{{ __('Insert image') }}" title="{{ __('Insert image') }}">
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
            {{-- Preview first when the text starts rendered: the order in the markup is the order on the screen (and for the Tab key) --}}
            {{-- A switch between two views, not a link: the active one is a raised segment, never underlined (underline means link) --}}
            <div class="inline-flex gap-0.5 rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-700/50" role="group">
                @if ($preview)
                    <button type="button" class="touch-target rounded-md px-2.5 py-0.5 text-sm" x-on:click="showPreview()" x-bind:aria-pressed="tab === 'preview'" x-bind:class="tab === 'preview' ? 'bg-white font-medium text-zinc-900 shadow-sm dark:bg-zinc-600 dark:text-white' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white'">{{ __('Preview') }}</button>
                    <button type="button" class="touch-target rounded-md px-2.5 py-0.5 text-sm" x-on:click="edit()" x-bind:aria-pressed="tab === 'write'" x-bind:class="tab === 'write' ? 'bg-white font-medium text-zinc-900 shadow-sm dark:bg-zinc-600 dark:text-white' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white'">{{ __('Edit') }}</button>
                @else
                    <button type="button" class="touch-target rounded-md px-2.5 py-0.5 text-sm" x-on:click="edit()" x-bind:aria-pressed="tab === 'write'" x-bind:class="tab === 'write' ? 'bg-white font-medium text-zinc-900 shadow-sm dark:bg-zinc-600 dark:text-white' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white'">{{ __('Write') }}</button>
                    <button type="button" class="touch-target rounded-md px-2.5 py-0.5 text-sm" x-on:click="showPreview()" x-bind:aria-pressed="tab === 'preview'" x-bind:class="tab === 'preview' ? 'bg-white font-medium text-zinc-900 shadow-sm dark:bg-zinc-600 dark:text-white' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white'">{{ __('Preview') }}</button>
                @endif
            </div>
        </div>
    </div>

    <div x-show="tab === 'write'" @if ($preview) x-cloak @endif class="relative" x-data="mentionable(@js($mentionsOnDemand ? ['users' => null, 'searchTasks' => true] : $mentions))" x-on:input="onInput($event)" x-on:keydown="onKeydown($event)" @if ($mentionsOnDemand) x-on:focusin.once="loadPeople()" @endif x-on:click.outside="open = false" x-on:paste="onPaste($event)"@if ($images !== null) x-on:drop="onDrop($event)" x-on:dragover.prevent @endif>
        {{-- grow: the field is as tall as its text (at least $rows lines), so a long description is never hidden behind a scrollbar --}}
        <flux:textarea :rows="$rows" :placeholder="$placeholder" :resize="$grow ? 'none' : 'vertical'" :class="$grow ? 'field-sizing-content min-h-24 not-supports-[field-sizing:content]:resize-y!' : ''" {{ $attributes->whereStartsWith('wire:model') }} />

        @if ($max !== null)
            <div
                class="mt-1 text-end text-xs tabular-nums"
                x-bind:class="count > max ? 'font-medium text-red-600 dark:text-red-400' : (count > max * 0.9 ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-500 dark:text-zinc-400')"
                title="{{ __('Counted are Unicode characters: an emoji made of several parts (a family, a flag) counts for each part.') }}"
            ><span x-text="count.toLocaleString(document.documentElement.lang || undefined)"></span> / <span x-text="max.toLocaleString(document.documentElement.lang || undefined)"></span></div>
            <span class="sr-only" role="status" x-text="count > max ? @js(__('The text is too long.')) : ''"></span>
        @endif

        <ul x-show="open && matches.length" x-cloak class="absolute z-20 mt-1 max-h-64 w-72 overflow-auto rounded-lg border border-zinc-200 bg-white p-1 text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-800" role="listbox">
            <template x-for="(item, index) in matches" x-bind:key="item.type + item.id">
                <li>
                    <div x-show="index === 0 || matches[index - 1].type !== item.type" class="px-2 pt-1 text-xs font-semibold uppercase text-zinc-400" x-text="item.type === 'user' ? @js(__('People')) : @js(__('Tasks'))"></div>
                    <button
                        type="button"
                        role="option"
                        class="flex w-full items-center gap-2 truncate rounded px-2 py-1 text-start"
                        x-bind:class="index === active ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
                        x-on:mousedown.prevent="select(item)"
                    >
                        {{-- People with their round avatar, like everywhere else (see x-user-avatar and User::avatarColor) --}}
                        <template x-if="item.type === 'user'">
                            <span class="inline-flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-full text-[0.5625rem] font-medium" x-bind:class="item.avatar ? '' : `bg-${item.color}-200 text-${item.color}-800`" aria-hidden="true">
                                <template x-if="item.avatar"><img x-bind:src="item.avatar" alt="" class="size-full object-cover"></template>
                                <template x-if="! item.avatar"><span x-text="item.initials"></span></template>
                            </span>
                        </template>
                        <span class="truncate" x-text="item.label"></span>
                        {{-- Only shown in the list; the mention itself keeps just the name --}}
                        <span x-show="item.note" class="text-xs text-zinc-400" x-text="'(' + item.note + ')'"></span>
                    </button>
                </li>
            </template>
        </ul>
    </div>

    <div x-show="tab === 'preview'" x-cloak class="min-h-24 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
        <flux:text x-show="loading">{{ __('Loading...') }}</flux:text>
        <div x-show="!loading" class="markdown" x-html="html || '<em>' + @js(__('Nothing to show.')) + '</em>'"></div>
    </div>
</div>
