<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Core\Access\Permissions;
use Filament\Facades\Filament;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creează primul cont de administrator după instalare. Parola nu se afișează și nu se trimite în clar:
 * se generează un link de setare a parolei (trimis pe e-mail sau afișat o singură dată în terminal).
 */
#[Signature('aw:create-admin {email} {name} {--role=super_admin} {--show-link : Afișează linkul de setare a parolei în terminal (dacă e-mailul nu este configurat încă)}')]
#[Description('Creează un cont de personal și trimite linkul de setare a parolei')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $role = (string) $this->option('role');
        if (! array_key_exists($role, Permissions::ROLES)) {
            $this->error('Rol necunoscut. Roluri: '.implode(', ', array_keys(Permissions::ROLES)));

            return self::FAILURE;
        }
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Adresă de e-mail invalidă.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->forceFill(['name' => (string) $this->argument('name'), 'password' => Str::password(40), 'is_active' => true])->save();
        }
        $user->syncRoles([$role]);

        if ($this->option('show-link')) {
            $token = Password::broker()->createToken($user);
            $url = Filament::getPanel('admin')->getResetPasswordUrl($token, $user);
            $this->warn('Link de setare a parolei (valabil '.config('auth.passwords.users.expire').' minute, o singură utilizare):');
            $this->line($url);
        } else {
            Password::broker()->sendResetLink(['email' => $user->email]);
            $this->info('Linkul de setare a parolei a fost trimis pe e-mail.');
        }
        $this->info("Cont {$email} cu rolul {$role}.".(in_array($role, User::MFA_REQUIRED_ROLES, true) ? ' La prima autentificare se configurează obligatoriu autentificarea în doi pași.' : ''));

        return self::SUCCESS;
    }
}
