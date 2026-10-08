<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:admin {email} {--revoke : Remove the administrator rights again}')]
#[Description('Makes a user an administrator of the whole application (sees and manages all projects)')]
class MakeUserAdmin extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('There is no user with this email address.');

            return self::FAILURE;
        }

        if ($this->option('revoke') && $user->isLastActiveAdmin()) {
            $this->components->error('The last active administrator cannot be revoked.');

            return self::FAILURE;
        }

        $user->update(['is_admin' => ! $this->option('revoke')]);

        $this->components->info($this->option('revoke')
            ? "{$user->name} is no longer an administrator."
            : "{$user->name} is now an administrator.");

        return self::SUCCESS;
    }
}
