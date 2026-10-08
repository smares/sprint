<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LocaleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('user:create {name} {email} {--password= : Password (a random one is generated if omitted)} {--admin : Create as an administrator of the whole application} {--locale= : Language as a two-letter code, e.g. de or en}')]
#[Description('Creates a new user (there is no public registration)')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $validator = Validator::make([...$this->arguments(), 'locale' => $this->option('locale') ?: config('app.locale')], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'locale' => ['required', 'in:'.implode(',', LocaleService::codes())],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $password = $this->option('password') ?: str()->password(16, symbols: false);

        User::create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $password,
            'is_admin' => (bool) $this->option('admin'),
            'locale' => $this->option('locale') ?: config('app.locale'),
        ]);

        $this->components->info("User created. Password: {$password}");

        return self::SUCCESS;
    }
}
