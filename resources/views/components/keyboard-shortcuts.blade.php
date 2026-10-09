{{-- Overview of the keyboard shortcuts (handled in app.js); opens with "?" or from the user menu. --}}
@php
    $groups = [
        __('Everywhere') => [
            ['/', __('Search or jump to…')],
            ['?', __('Show this overview')],
        ],
        __('List and board') => [
            ['c', __('Create a task')],
            ['j', __('Open the next task')],
            ['k', __('Open the previous task')],
            ['Esc', __('Close the task')],
        ],
        __('Open task') => [
            ['e', __('Mark as done or open again')],
        ],
    ];
@endphp

<flux:modal name="keyboard-shortcuts" class="w-full max-w-md">
    <div class="space-y-6">
        <flux:heading size="lg">{{ __('Keyboard shortcuts') }}</flux:heading>

        @foreach ($groups as $group => $shortcuts)
            <div>
                <flux:subheading class="mb-2">{{ $group }}</flux:subheading>
                <dl class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach ($shortcuts as [$key, $label])
                        <div class="flex items-center justify-between py-1.5">
                            <dt class="text-sm text-zinc-700 dark:text-zinc-200">{{ $label }}</dt>
                            <dd><kbd class="rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 font-mono text-xs text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200">{{ $key }}</kbd></dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach

        <flux:text size="sm">{{ __('Shortcuts do nothing while you type in a field.') }}</flux:text>
    </div>
</flux:modal>
