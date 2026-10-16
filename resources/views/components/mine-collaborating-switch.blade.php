{{-- The switch of "My tasks" (list and board): tasks one only collaborates on are shown too. It binds to the page's property. --}}
<div {{ $attributes->class('mb-4') }}>
    <flux:switch align="left" wire:model.live="includeCollaborating" :label="__('Also tasks I collaborate on')" />
</div>
