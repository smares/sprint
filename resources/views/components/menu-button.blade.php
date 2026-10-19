@props(['role' => 'menuitem'])

{{-- An entry of the menus the pages draw themselves once (x-assignee-menu, x-card-move-menu): a button that looks like a Flux menu item. --}}
<button type="button" role="{{ $role }}" {{ $attributes->class('flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm text-zinc-800 hover:bg-zinc-100 focus:bg-zinc-100 focus:outline-hidden dark:text-white dark:hover:bg-zinc-600 dark:focus:bg-zinc-600') }}>{{ $slot }}</button>
