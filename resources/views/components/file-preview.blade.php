<div x-data="{ preview: null }" x-on:preview-file.window="preview = $event.detail" x-on:keydown.escape.window="preview = null">
    <template x-teleport="body">
        <div x-show="preview" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex flex-col bg-black/80 p-4" x-on:click.self="preview = null" role="dialog" aria-modal="true">
            <div class="mb-3 flex items-center gap-3 text-white" x-on:click.self="preview = null">
                <span class="min-w-0 flex-1 truncate font-medium" x-text="preview?.name"></span>
                <a :href="preview?.download" class="inline-flex items-center gap-1.5 rounded-md bg-white/10 px-3 py-1.5 text-sm hover:bg-white/20"><flux:icon.arrow-down-tray variant="micro" />{{ __('Download') }}</a>
                <button type="button" class="rounded-md bg-white/10 p-1.5 hover:bg-white/20" x-on:click="preview = null" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><flux:icon.x-mark variant="mini" /></button>
            </div>
            <div class="flex min-h-0 flex-1 items-center justify-center" x-on:click.self="preview = null">
                <template x-if="preview?.kind === 'image'">
                    <img :src="preview.url" :alt="preview.name" class="max-h-full max-w-full rounded object-contain">
                </template>
                <template x-if="preview && preview.kind !== 'image'">
                    <iframe :src="preview.url" :title="preview.name" class="h-full w-full max-w-5xl rounded bg-white"></iframe>
                </template>
            </div>
        </div>
    </template>
</div>
