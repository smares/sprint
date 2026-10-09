@props(['reactable', 'target', 'canReact' => false])

{{-- The emoji reactions under a task or a comment, with a menu to pick one (one per person): the quick picks or any emoji typed or pasted. The page provides react(target, id, emoji). --}}
@php
    $groups = $reactable->reactions->groupBy('emoji')->sortBy(fn ($group) => [-$group->count(), $group->min('id')]);
    $mine = $reactable->reactions->firstWhere('user_id', auth()->id())?->emoji;
    $id = $reactable->getKey();
@endphp

@if ($groups->isNotEmpty() || $canReact)
    <div class="flex flex-wrap items-center gap-1" wire:key="reactions-{{ $target }}-{{ $id }}">
        @foreach ($groups as $emoji => $group)
            @php($names = $group->map(fn ($reaction) => $reaction->user->name)->implode(', '))
            <flux:button
                type="button"
                size="xs"
                :variant="$mine === $emoji ? 'primary' : 'outline'"
                wire:click="react('{{ $target }}', {{ $id }}, '{{ $emoji }}')"
                :disabled="! $canReact"
                :tooltip="$names"
                aria-label="{{ $emoji }} {{ $names }}"
            >{{ $emoji }} {{ $group->count() }}</flux:button>
        @endforeach

        @if ($canReact)
            {{-- The menu's content is only built once it is opened: a task page has a menu under every comment --}}
            <flux:dropdown x-data="{ built: false }">
                <flux:button type="button" size="xs" variant="ghost" icon="face-smile" aria-label="{{ __('React') }}" :tooltip="__('React')" x-on:click="built = true" />

                <flux:popover class="w-64 p-3">
                    <template x-if="built">
                    <div class="space-y-3">
                    <div class="flex justify-between gap-1">
                        @foreach (\App\Emoji::quick() as $emoji => $label)
                            <button type="button" class="rounded-md p-1.5 text-xl leading-none hover:bg-zinc-100 dark:hover:bg-zinc-700" title="{{ $label }}" aria-label="{{ $label }}" wire:click="react('{{ $target }}', {{ $id }}, '{{ $emoji }}')">{{ $emoji }}</button>
                        @endforeach
                    </div>

                    {{-- No form: on the task page this sits inside the task's form, and forms cannot be nested. Only an emoji stays in the field, see emojiField in app.js --}}
                    <div x-data="emojiField('{{ $target }}', {{ $id }})" class="space-y-1">
                        <flux:input size="sm" x-model="emoji" x-on:input="keepEmoji($event)" x-on:compositionend="keepEmoji($event)" x-on:keydown.enter="send($event)" enterkeyhint="send" autocomplete="off" :placeholder="__('Any other emoji …')" :aria-label="__('Any other emoji …')" />
                        <flux:text size="sm" class="text-zinc-500">{{ __('Type or paste one, then press Enter. Windows: Win + . · Mac: Ctrl + Cmd + Space') }}</flux:text>
                    </div>
                    </div>
                    </template>
                </flux:popover>
            </flux:dropdown>
        @endif
    </div>
@endif
