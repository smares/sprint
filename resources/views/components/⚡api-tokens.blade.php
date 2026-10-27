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
        <flux:heading level="2" size="lg">{{ __('API access for AI agents') }}</flux:heading>
        <flux:text class="mt-1">{{ __('With a token, agents such as Claude Code can read, create and change tasks via MCP – with exactly your permissions and under your name. Deleting is not possible.') }}</flux:text>
    </div>

    @if ($createdToken)
        <flux:callout variant="success" icon="key" x-data="{ copied: false }">
            <flux:callout.heading>{{ __('Token created – copy it now') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('It is only shown this one time.') }}
                <code class="mt-2 block break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">{{ $createdToken }}</code>
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" x-on:click="navigator.clipboard.writeText({{ Js::from($createdToken) }}); copied = true" x-text="copied ? {{ Js::from(__('Copied')) }} : {{ Js::from(__('Copy token')) }}">{{ __('Copy token') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="dismissToken">{{ __('Finish') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    {{-- Two ways to connect: the Claude app (Connectors) and Claude Code in the terminal. A new token is filled in; otherwise a placeholder stands there --}}
    @php($tokenValue = $createdToken ?? '<'.__('your token').'>')
    <div x-data="{ tool: 'app' }" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading level="3">{{ __('Connect an agent') }}</flux:heading>
            <div class="inline-flex gap-0.5 rounded-lg bg-zinc-100 p-0.5 text-sm dark:bg-zinc-700/50" role="group" aria-label="{{ __('Connect an agent') }}">
                @foreach (['app' => __('Claude app'), 'cli' => __('Claude Code (terminal)')] as $key => $label)
                    <button type="button" class="touch-target rounded-md px-2.5 py-0.5" x-on:click="tool = '{{ $key }}'" x-bind:aria-pressed="tool === '{{ $key }}'" x-bind:class="tool === '{{ $key }}' ? 'bg-white font-medium text-zinc-900 shadow-sm dark:bg-zinc-600 dark:text-white' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white'">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div x-show="tool === 'app'" class="space-y-3 text-sm">
            <ol class="list-decimal space-y-2 ps-5">
                @unless ($createdToken)
                    <li>{{ __('Create a token below and copy it; it is only shown once.') }}</li>
                @endunless
                <li>{{ __('Open the connectors of the Claude app and add a custom connector.') }}</li>
                <li>
                    {{ __('Name it Sprint and enter this address:') }}
                    <code class="mt-1 block select-all break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">{{ url('/mcp') }}</code>
                </li>
                <li>
                    {{ __('Choose no sign-in and send your own request header instead. Its name:') }}
                    <code class="mt-1 block select-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">Authorization</code>
                    {{ __('Its value:') }}
                    <code class="mt-1 block select-all break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">Bearer {{ $tokenValue }}</code>
                </li>
            </ol>
            <flux:text size="sm">{{ __('Custom request headers are a beta feature of the Claude app and not available to every account. In a team or enterprise plan the connector belongs to the whole organization, so everyone in it works with this token; use a token of a service account or a read-only token there. Claude reaches the address from the internet, so a Sprint that is only reachable internally does not work. A token cannot be changed in the connector later: when it expires, add the connector again.') }}</flux:text>
        </div>

        <div x-show="tool === 'cli'" x-cloak class="space-y-2 text-sm">
            <p>{{ __('Run this in a terminal; the agent then works with your permissions:') }}</p>
            <code class="block select-all break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">claude mcp add --transport http sprint {{ url('/mcp') }} --header "Authorization: Bearer {{ $tokenValue }}"</code>
        </div>
    </div>

    <form wire:submit="create" class="space-y-4">
        <flux:input wire:model="name" :label="__('Token name')" placeholder="{{ __('e.g. Claude Code on the laptop') }}" />
        <flux:select wire:model="expiry" :label="__('Validity')">
            <flux:select.option value="30">{{ __('30 days') }}</flux:select.option>
            <flux:select.option value="90">{{ __('90 days') }}</flux:select.option>
            <flux:select.option value="365">{{ __('1 year') }}</flux:select.option>
        </flux:select>
        <flux:switch wire:model="readOnly" :label="__('Read only')" :description="__('The agent can view tasks but not create or change anything.')" />
        @if ($this->confirmed)
            <x-unlocked-note :until="$this->confirmedUntil" />
        @else
            <flux:input wire:model="password" type="password" :label="__('Current password')" :description="__('A token acts in your name, so creating one needs your password.')" autocomplete="current-password" />
        @endif
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
                            · {{ $token->expires_at ? ($token->expires_at->isPast() ? __('expired') : __('valid until :date', ['date' => auth()->user()->inTimezone($token->expires_at)->isoFormat('L')])) : __('valid indefinitely') }}
                        </flux:text>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke token “:name”? Agents using it lose access immediately.', ['name' => $token->name]) }}" aria-label="{{ __('Revoke token') }}" tooltip="{{ __('Revoke token') }}" />
                </div>
            @endforeach
        </div>
    @endif
</div>
