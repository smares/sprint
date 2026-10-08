@props(['href' => null])

{{-- The application's name with its mark, the same in the header and on the login page. --}}
<flux:brand :href="$href ?? route('projects.index')" :name="config('app.name')" {{ $attributes }}>
    <x-slot name="logo" class="size-7 rounded-md bg-zinc-800 text-white dark:bg-white dark:text-zinc-800">
        <flux:icon.bolt variant="micro" />
    </x-slot>
</flux:brand>
