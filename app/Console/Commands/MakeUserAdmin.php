<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:admin {email} {--revoke : Admin-Rechte wieder entziehen}')]
#[Description('Macht einen Benutzer zum Administrator der ganzen Anwendung (sieht und verwaltet alle Projekte)')]
class MakeUserAdmin extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('Es gibt keinen Benutzer mit dieser E-Mail-Adresse.');

            return self::FAILURE;
        }

        $user->update(['is_admin' => ! $this->option('revoke')]);

        $this->components->info($this->option('revoke')
            ? "{$user->name} ist kein Administrator mehr."
            : "{$user->name} ist jetzt Administrator.");

        return self::SUCCESS;
    }
}
