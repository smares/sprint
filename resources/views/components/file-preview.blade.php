{{-- Preview of images, PDFs and text files in a Flux modal; opened by dispatching `preview-file` with url, download, name and kind. --}}
<div x-data="{ preview: null }" x-on:preview-file.window="preview = $event.detail; $flux.modal('file-preview').show()">
    <flux:modal name="file-preview" class="w-full max-w-5xl" x-on:close="preview = null">
        <div class="space-y-4">
            <flux:heading size="lg" class="truncate pe-8" x-text="preview?.name"></flux:heading>

            <div class="flex items-center justify-center">
                <template x-if="preview?.kind === 'image'">
                    <img :src="preview.url" :alt="preview.name" class="max-h-[75vh] max-w-full rounded object-contain">
                </template>
                <template x-if="preview && preview.kind !== 'image'">
                    <iframe :src="preview.url" :title="preview.name" class="h-[75vh] w-full rounded border border-zinc-200 bg-white dark:border-zinc-700"></iframe>
                </template>
            </div>

            <div class="flex">
                <flux:spacer />
                <flux:button icon="arrow-down-tray" href="#" x-bind:href="preview?.download">{{ __('Download') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
