<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:deactivate {email} {--reactivate : Konto wieder aktivieren}')]
#[Description('Deaktiviert ein Benutzerkonto (kein Login mehr, keine Zuweisungen oder Mails), die Historie bleibt erhalten')]
class DeactivateUser extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('Es gibt keinen Benutzer mit dieser E-Mail-Adresse.');

            return self::FAILURE;
        }

        if ($this->option('reactivate')) {
            $user->reactivate();
            $this->components->info("{$user->name} ist wieder aktiv.");

            return self::SUCCESS;
        }

        if ($user->isLastActiveAdmin()) {
            $this->components->error('Das ist der letzte aktive Administrator und kann nicht deaktiviert werden.');

            return self::FAILURE;
        }

        $user->deactivate();
        $this->components->info("{$user->name} ist deaktiviert und abgemeldet.");

        return self::SUCCESS;
    }
}
