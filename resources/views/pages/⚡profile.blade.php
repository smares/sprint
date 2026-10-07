<?php

use App\Locale;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Profil')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $emailPassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $digest = true;

    public string $locale = '';

    #[Url(as: 'tab', except: 'profile')]
    public string $tab = 'profile';

    public function mount(): void
    {
        if (! in_array($this->tab, ['profile', 'security', 'api'], true)) {
            $this->tab = 'profile';
        }

        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
        $this->digest = auth()->user()->digest_enabled;
        $this->locale = auth()->user()->preferredLocale();
    }

    public function updatedLocale(string $value): void
    {
        $this->validate(['locale' => ['required', Rule::in(Locale::codes())]]);

        auth()->user()->update(['locale' => $value]);
        session()->put('locale', $value);
        Locale::apply($value);

        $this->redirectRoute('profile', navigate: true);
    }

    public function updatedDigest(bool $value): void
    {
        auth()->user()->update(['digest_enabled' => $value]);

        Flux::toast(variant: 'success', text: $value ? 'Tageszusammenfassung eingeschaltet.' : 'Tageszusammenfassung ausgeschaltet.');
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], attributes: ['name' => 'Name', 'email' => 'E-Mail']);

        if (Str::lower($validated['email']) !== Str::lower($user->email)) {
            $this->validate(['emailPassword' => ['required']], attributes: ['emailPassword' => 'Passwort']);

            if (! Hash::check($this->emailPassword, $user->password)) {
                throw ValidationException::withMessages(['emailPassword' => 'Das Passwort stimmt nicht.']);
            }
        }

        $user->update(['name' => trim($validated['name']), 'email' => $validated['email']]);

        $this->reset('emailPassword');
        Flux::toast(variant: 'success', text: 'Profil gespeichert.');
    }

    public function changePassword(): void
    {
        $user = auth()->user();

        $this->validate([
            'currentPassword' => ['required'],
            'newPassword' => ['required', 'string', 'min:8', 'max:255', 'same:newPasswordConfirmation', 'different:currentPassword'],
            'newPasswordConfirmation' => ['required'],
        ], attributes: [
            'currentPassword' => 'Aktuelles Passwort',
            'newPassword' => 'Neues Passwort',
            'newPasswordConfirmation' => 'Wiederholung',
        ]);

        if (! Hash::check($this->currentPassword, $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => 'Das aktuelle Passwort stimmt nicht.']);
        }

        $user->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        Flux::toast(variant: 'success', text: 'Passwort geändert.');
    }
};
?>

<div class="max-w-xl space-y-6">
    <div>
        <flux:heading size="xl">Profil</flux:heading>
        <flux:text class="mt-1">Dein Konto, seine Sicherheit und der Zugang für KI-Agenten.</flux:text>
    </div>

    <flux:tab.group>
        <flux:tabs wire:model="tab" scrollable>
            <flux:tab name="profile" icon="user">Profil</flux:tab>
            <flux:tab name="security" icon="shield-check">Sicherheit</flux:tab>
            <flux:tab name="api" icon="key">API-Zugang</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="profile" class="space-y-8">
            <div>
                <flux:heading size="lg">Persönliche Angaben</flux:heading>
                <flux:text class="mt-1">Dein Name und deine E-Mail-Adresse erscheinen bei Zuweisungen, Kommentaren und in Benachrichtigungen.</flux:text>
            </div>

            <form wire:submit="saveProfile" class="space-y-4">
                <flux:input wire:model="name" label="Name" autocomplete="name" />
                <flux:input wire:model="email" type="email" label="E-Mail" autocomplete="email" />
                @if (\Illuminate\Support\Str::lower($email) !== \Illuminate\Support\Str::lower(auth()->user()->email))
                    <flux:input wire:model="emailPassword" type="password" label="Passwort zur Bestätigung" description="Zum Ändern der E-Mail-Adresse brauchst du dein aktuelles Passwort." autocomplete="current-password" />
                @endif
                <flux:button type="submit" variant="primary">Speichern</flux:button>
            </form>

            <flux:separator />

            <flux:select variant="listbox" wire:model.live="locale" label="Sprache" description="Oberfläche und E-Mails erscheinen in dieser Sprache.">
                @foreach (\App\Locale::available() as $code => $name)
                    <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:separator />

            <flux:switch wire:model.live="digest" label="Tageszusammenfassung per E-Mail" description="Werktags am Morgen eine Mail mit deinen überfälligen und bald fälligen Aufgaben, nur wenn es etwas zu berichten gibt." />
        </flux:tab.panel>

        <flux:tab.panel name="security" class="space-y-8">
            <form wire:submit="changePassword" class="space-y-4">
                <flux:heading size="lg">Passwort ändern</flux:heading>
                <flux:input wire:model="currentPassword" type="password" label="Aktuelles Passwort" autocomplete="current-password" />
                <flux:input wire:model="newPassword" type="password" label="Neues Passwort" description="Mindestens 8 Zeichen." autocomplete="new-password" />
                <flux:input wire:model="newPasswordConfirmation" type="password" label="Neues Passwort wiederholen" autocomplete="new-password" />
                <flux:button type="submit">Passwort ändern</flux:button>
            </form>

            <flux:separator />

            <livewire:security />
        </flux:tab.panel>

        <flux:tab.panel name="api">
            <livewire:api-tokens />
        </flux:tab.panel>
    </flux:tab.group>
</div>
