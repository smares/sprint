<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('user:create {name} {email} {--password= : Passwort (sonst wird eines erzeugt)}')]
#[Description('Legt einen neuen Benutzer an (es gibt keine öffentliche Registrierung)')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $validator = Validator::make($this->arguments(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
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
        ]);

        $this->components->info("Benutzer angelegt. Passwort: {$password}");

        return self::SUCCESS;
    }
}
