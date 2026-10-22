<?php

use App\Services\SsoSettings;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public bool $enabled = false;

    /** One domain per line (or separated by commas or spaces). */
    public string $domains = '';

    public function mount(SsoSettings $sso): void
    {
        Gate::authorize('administer');

        $this->enabled = $sso->isEnabled();
        $this->domains = implode("\n", $sso->domains());
    }

    public function hydrate(): void
    {
        Gate::authorize('administer');
    }

    public function save(SsoSettings $sso): void
    {
        Gate::authorize('administer');

        $domains = collect(preg_split('/[\s,;]+/', Str::lower($this->domains), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $domain) => ltrim($domain, '@'))
            ->unique()
            ->values();

        $this->validate(
            ['enabled' => ['boolean'], 'domains' => ['nullable', 'string', 'max:2000']],
            attributes: ['domains' => __('Allowed domains')],
        );

        $invalid = $domains->first(fn (string $domain) => preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1);

        if ($invalid !== null) {
            $this->addError('domains', __(':domain is not a domain name, for example example.com.', ['domain' => $invalid]));

            return;
        }

        if ($this->enabled && ! $sso->hasCredentials()) {
            $this->addError('enabled', __('The Google credentials are missing on the server (see the instructions below).'));

            return;
        }

        $sso->update($this->enabled, $domains->all());
        $this->domains = $domains->implode("\n");

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function rendering(View $view): void
    {
        $view->title(__('Single sign-on'));
    }
};
?>

<div class="max-w-2xl space-y-6">
    <div>
        <flux:heading level="1" size="xl" class="mb-1">{{ __('Single sign-on') }}</flux:heading>
        <flux:text>{{ __('People can sign in with their Google account, register with it and connect it to their account.') }}</flux:text>
    </div>

    @if (! app(SsoSettings::class)->hasCredentials())
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Google credentials missing')">
            <flux:callout.text>{{ __('Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in the environment of the server first (see the instructions below).') }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="surface space-y-5 p-4 sm:p-6">
        <flux:switch wire:model="enabled" :label="__('Sign in with Google')" :description="__('Shows the button on the login page and in the profile. People who already have an account sign in with it when their Google address is the one of their account.')" />

        <flux:textarea wire:model="domains" rows="3" :label="__('Allowed domains')" :description="__('Only people with a Google account at one of these domains can register themselves, one domain per line (for example example.com). Without a domain, nobody can register. Accounts of a Google Workspace must belong to that Workspace.')" placeholder="example.com" />

        <flux:text size="sm">{{ __('New accounts are not administrators and do not see any projects. All administrators get an email and can give them the rights and projects.') }}</flux:text>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>

    <div class="surface space-y-2 p-4 sm:p-6">
        <flux:heading>{{ __('Setting up Google') }}</flux:heading>
        <flux:text size="sm">{!! __('In the Google Cloud Console create an OAuth client of the type <em>Web application</em> and enter this address as <em>Authorized redirect URI</em>:') !!}</flux:text>
        <flux:input :value="route('auth.google.callback')" readonly copyable />
        <flux:text size="sm">{!! __('Put the client ID and the secret into the environment of the server as GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET and restart it. For a Google Workspace, set the consent screen to <em>Internal</em> to keep other Google accounts out altogether.') !!}</flux:text>
    </div>
</div>
