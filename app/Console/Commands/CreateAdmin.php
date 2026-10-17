<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('optica:create-admin {email} {name} {--locale=ro}')]
#[Description('Create a superadministrator (until the web installer does this)')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $password = (string) $this->secret('Parolă / Jelszó');
        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password, 'locale' => $this->option('locale')],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', Password::min(10)->letters()->numbers()],
                'locale' => ['in:ro,hu'],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        $user = User::create([
            'name' => $this->argument('name'),
            'email' => strtolower($this->argument('email')),
            'password' => $password,
            'locale' => $this->option('locale'),
            'all_locations' => true,
        ]);
        $user->roles()->attach(Role::where('key', 'superadmin')->value('id'));
        $this->info("OK: {$user->email}. 2FA is required at first sign-in.");

        return self::SUCCESS;
    }
}
