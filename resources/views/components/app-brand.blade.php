@props(['href' => null])

{{-- The application's name with its mark, the same in the header and on the login page. --}}
<flux:brand :href="$href ?? route('projects.index')" :name="config('app.name')" {{ $attributes }}>
    <x-slot name="logo" class="size-7">
        <x-app-logo class="size-7" />
    </x-slot>
</flux:brand>
