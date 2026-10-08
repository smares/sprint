<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:deactivate {email} {--reactivate : Reactivate the account}')]
#[Description('Deactivates a user account (no more sign-in, assignments or mails); the history is kept')]
class DeactivateUser extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('There is no user with this email address.');

            return self::FAILURE;
        }

        if ($this->option('reactivate')) {
            $user->reactivate();
            $this->components->info("{$user->name} is active again.");

            return self::SUCCESS;
        }

        if ($user->isLastActiveAdmin()) {
            $this->components->error('This is the last active administrator and cannot be deactivated.');

            return self::FAILURE;
        }

        $user->deactivate();
        $this->components->info("{$user->name} is deactivated and signed out.");

        return self::SUCCESS;
    }
}
