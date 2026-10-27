@props(['until'])

{{-- The password was confirmed: until when changes of this kind need no password (see ConfirmsPassword) --}}
<flux:text size="sm" {{ $attributes->class('flex items-center gap-1.5 text-green-800 dark:text-green-300') }}>
    <flux:icon.lock-open variant="micro" class="shrink-0" />{{ __('Unlocked until :time', ['time' => auth()->user()->inTimezone($until)->isoFormat('LT')]) }}
</flux:text>
