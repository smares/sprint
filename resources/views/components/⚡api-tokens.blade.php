<?php

use App\Concerns\ConfirmsPassword;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use ConfirmsPassword;

    public string $name = '';

    public bool $readOnly = false;

    public string $expiry = '90';

    public ?string $createdToken = null;

    #[Computed]
    public function tokens(): Collection
    {
        return auth()->user()->tokens()->latest()->get();
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'expiry' => ['required', 'in:30,90,365'],
        ], attributes: ['name' => __('Name'), 'expiry' => __('Validity')]);

        abort_unless(auth()->user()->isActive(), 403);

        if (! $this->confirmed) {
            $this->confirmPassword();
        }

        $this->createdToken = auth()->user()->createToken(
            trim($validated['name']),
            $this->readOnly ? ['read'] : ['read', 'write'],
            now()->addDays((int) $validated['expiry']),
        )->plainTextToken;

        $this->reset('name', 'readOnly');
        unset($this->tokens);
    }

    public function dismissToken(): void
    {
        $this->createdToken = null;
    }

    public function revoke(int $tokenId): void
    {
        auth()->user()->tokens()->whereKey($tokenId)->delete();

        unset($this->tokens);
        Flux::toast(variant: 'success', text: __('Token revoked.'));
    }
};
?>

<div class="space-y-4">
    <div>
        <flux:heading size="lg">{{ __('API access for AI agents') }}</flux:heading>
        <flux:text class="mt-1">{{ __('With a token, agents such as Claude Code can read, create and change tasks via MCP – with exactly your permissions and under your name. Deleting is not possible.') }}</flux:text>
    </div>

    @if ($createdToken)
        <flux:callout variant="success" icon="key" x-data="{ copied: false }">
            <flux:callout.heading>{{ __('Token created – copy it now') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('It is only shown this one time.') }}
                <code class="mt-2 block break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">{{ $createdToken }}</code>
                <code class="mt-2 block break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">claude mcp add --transport http sprint {{ url('/mcp') }} --header "Authorization: Bearer {{ $createdToken }}"</code>
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" x-on:click="navigator.clipboard.writeText(@js($createdToken)); copied = true" x-text="copied ? @js(__('Copied')) : @js(__('Copy token'))">{{ __('Copy token') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="dismissToken">{{ __('Finish') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <form wire:submit="create" class="space-y-4">
        <flux:input wire:model="name" :label="__('Token name')" placeholder="{{ __('e.g. Claude Code on the laptop') }}" />
        <flux:select wire:model="expiry" :label="__('Validity')">
            <flux:select.option value="30">{{ __('30 days') }}</flux:select.option>
            <flux:select.option value="90">{{ __('90 days') }}</flux:select.option>
            <flux:select.option value="365">{{ __('1 year') }}</flux:select.option>
        </flux:select>
        <flux:switch wire:model="readOnly" :label="__('Read only')" :description="__('The agent can view tasks but not create or change anything.')" />
        @unless ($this->confirmed)
            <flux:input wire:model="password" type="password" :label="__('Current password')" :description="__('A token acts in your name, so creating one needs your password.')" autocomplete="current-password" />
        @endunless
        <flux:button type="submit">{{ __('Create token') }}</flux:button>
    </form>

    @if ($this->tokens->isNotEmpty())
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->tokens as $token)
                <div wire:key="token-{{ $token->id }}" class="flex items-center justify-between gap-3 p-3">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ $token->name }}</div>
                        <flux:text size="sm">
                            {{ $token->can('write') ? __('Read and write') : __('Read only') }}
                            · {{ $token->last_used_at ? __('last used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('never used') }}
                            · {{ $token->expires_at ? ($token->expires_at->isPast() ? __('expired') : __('valid until :date', ['date' => $token->expires_at->isoFormat('L')])) : __('valid indefinitely') }}
                        </flux:text>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke token “:name”? Agents using it lose access immediately.', ['name' => $token->name]) }}" aria-label="{{ __('Revoke token') }}" />
                </div>
            @endforeach
        </div>
    @endif
</div>
