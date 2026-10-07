<?php

use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public bool $readOnly = false;

    public string $expiry = '90';

    public ?string $createdToken = null;

    #[Computed]
    public function tokens()
    {
        return auth()->user()->tokens()->latest()->get();
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'expiry' => ['required', 'in:30,90,365,never'],
        ], attributes: ['name' => 'Name', 'expiry' => 'Gültigkeit']);

        abort_unless(auth()->user()->isActive(), 403);

        $this->createdToken = auth()->user()->createToken(
            trim($validated['name']),
            $this->readOnly ? ['read'] : ['read', 'write'],
            $validated['expiry'] === 'never' ? null : now()->addDays((int) $validated['expiry']),
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
        Flux::toast(variant: 'success', text: 'Token widerrufen.');
    }
};
?>

<div class="space-y-4">
    <div>
        <flux:heading size="lg">API-Zugang für KI-Agenten</flux:heading>
        <flux:text class="mt-1">Mit einem Token können Agenten wie Claude Code über MCP Aufgaben lesen, anlegen und ändern – mit genau deinen Rechten und unter deinem Namen. Löschen ist nicht möglich.</flux:text>
    </div>

    @if ($createdToken)
        <flux:callout variant="success" icon="key" x-data="{ copied: false }">
            <flux:callout.heading>Token erstellt – jetzt kopieren</flux:callout.heading>
            <flux:callout.text>
                Er wird nur dieses eine Mal angezeigt.
                <code class="mt-2 block break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">{{ $createdToken }}</code>
                <code class="mt-2 block break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">claude mcp add --transport http sprint {{ url('/mcp') }} --header "Authorization: Bearer {{ $createdToken }}"</code>
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" x-on:click="navigator.clipboard.writeText(@js($createdToken)); copied = true" x-text="copied ? 'Kopiert' : 'Token kopieren'">Token kopieren</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="dismissToken">Fertig</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <form wire:submit="create" class="space-y-4">
        <flux:input wire:model="name" label="Name des Tokens" placeholder="z. B. Claude Code auf dem Laptop" />
        <flux:select wire:model="expiry" label="Gültigkeit">
            <flux:select.option value="30">30 Tage</flux:select.option>
            <flux:select.option value="90">90 Tage</flux:select.option>
            <flux:select.option value="365">1 Jahr</flux:select.option>
            <flux:select.option value="never">Unbegrenzt</flux:select.option>
        </flux:select>
        <flux:switch wire:model="readOnly" label="Nur lesen" description="Der Agent kann Aufgaben ansehen, aber nichts anlegen oder ändern." />
        <flux:button type="submit">Token erstellen</flux:button>
    </form>

    @if ($this->tokens->isNotEmpty())
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->tokens as $token)
                <div wire:key="token-{{ $token->id }}" class="flex items-center justify-between gap-3 p-3">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ $token->name }}</div>
                        <flux:text size="sm">
                            {{ $token->can('write') ? 'Lesen und schreiben' : 'Nur lesen' }}
                            · {{ $token->last_used_at ? 'zuletzt benutzt '.$token->last_used_at->diffForHumans() : 'noch nie benutzt' }}
                            · {{ $token->expires_at ? ($token->expires_at->isPast() ? 'abgelaufen' : 'gültig bis '.$token->expires_at->format('d.m.Y')) : 'unbegrenzt gültig' }}
                        </flux:text>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="revoke({{ $token->id }})" wire:confirm="Token „{{ $token->name }}“ widerrufen? Agenten damit verlieren sofort den Zugriff." aria-label="Token widerrufen" />
                </div>
            @endforeach
        </div>
    @endif
</div>
