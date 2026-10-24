<?php

/**
 * Instalare din browser, pentru găzduiri cPanel fără Terminal.
 *
 * Deschideți https://domeniul-dvs/instalare.php, introduceți parola bazei de date din .env (dovedește că aveți acces
 * la configurarea serverului) și datele administratorului. După instalare, pagina se dezactivează (fișier de blocare)
 * și încearcă să se șteargă singură.
 */

declare(strict_types=1);
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// aplicația este fie în folderul părinte (domeniu îndreptat spre public/), fie în ~/anotherworldcluj (pagina în public_html)
$root = is_file(dirname(__DIR__).'/artisan') ? dirname(__DIR__) : dirname(__DIR__).'/anotherworldcluj';
$lock = $root.'/storage/app/instalare.lock';
$attemptsFile = $root.'/storage/framework/instalare-incercari';

session_name('aw_install');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** @return array<string, string> */
function readEnv(string $path): array
{
    $values = [];
    foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
            $value = trim($m[2]);
            if (preg_match('/^"(.*)"$/s', $value, $q) || preg_match("/^'(.*)'$/s", $value, $q)) {
                $value = $q[1];
            }
            $values[$m[1]] = $value;
        }
    }

    return $values;
}

/** @return array<int, array{0: bool, 1: string, 2?: string}> */
function requirements(string $root, array $env): array
{
    $checks = [];
    $checks[] = [version_compare(PHP_VERSION, '8.3.0', '>='), 'PHP 8.3 sau mai nou', 'versiunea actuală: '.PHP_VERSION.' — schimbați din MultiPHP Manager'];
    foreach (['pdo_mysql', 'mbstring', 'intl', 'gd', 'zip', 'fileinfo', 'openssl', 'curl', 'exif', 'sodium'] as $ext) {
        $checks[] = [extension_loaded($ext), "Extensia PHP „{$ext}”", 'activați-o din Select PHP Version'];
    }
    $checks[] = [is_file($root.'/artisan'), 'Aplicația găsită în '.$root, 'dezarhivați aplicația în folderul anotherworldcluj, alături de public_html'];
    $checks[] = [is_file($root.'/vendor/autoload.php'), 'Bibliotecile aplicației (folderul vendor)', 'arhiva nu a fost dezarhivată complet'];
    if (! is_file(dirname(__DIR__).'/artisan')) {
        $checks[] = [is_file(__DIR__.'/.aw-public') && is_file(__DIR__.'/build/manifest.json'), 'Fișierele publice în '.__DIR__, 'dezarhivați aici arhiva pentru public_html (cu fișierul .aw-public)'];
    }
    $checks[] = [is_file($root.'/.env'), 'Fișierul .env', 'copiați .env.example ca .env și completați-l'];
    $checks[] = [is_file($root.'/.env') && is_writable($root.'/.env'), 'Fișierul .env poate fi scris (pentru cheia aplicației)', 'drepturi 644 pe .env'];
    foreach (['storage', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $dir) {
        $checks[] = [is_dir($root.'/'.$dir) && is_writable($root.'/'.$dir), "Folderul {$dir} poate fi scris", 'drepturi 755 pe folder'];
    }
    $dbOk = false;
    $dbError = 'completați DB_DATABASE, DB_USERNAME, DB_PASSWORD în .env';
    if (! empty($env['DB_DATABASE']) && extension_loaded('pdo_mysql')) {
        try {
            new PDO('mysql:host='.($env['DB_HOST'] ?? 'localhost').';port='.($env['DB_PORT'] ?? '3306').';dbname='.$env['DB_DATABASE'].';charset=utf8mb4', $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_TIMEOUT => 5]);
            $dbOk = true;
        } catch (Throwable $e) {
            $dbError = 'conexiune eșuată: '.$e->getMessage();
        }
    }
    $checks[] = [$dbOk, 'Conexiunea la baza de date', $dbError];

    return $checks;
}

function cliPhpPath(): ?string
{
    foreach (['/opt/cpanel/ea-php85/root/usr/bin/php', '/opt/cpanel/ea-php84/root/usr/bin/php', '/opt/cpanel/ea-php83/root/usr/bin/php', '/opt/alt/php85/usr/bin/php', '/opt/alt/php84/usr/bin/php', '/opt/alt/php83/usr/bin/php'] as $path) {
        if (@is_file($path)) {
            return $path;
        }
    }

    return null;
}

$env = readEnv($root.'/.env');
$installed = is_file($lock);
$checks = $installed ? [] : requirements($root, $env);
$ready = ! $installed && ! in_array(false, array_column($checks, 0), true);
$errors = [];
$log = [];
$done = false;

if (! $installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempts = (int) @file_get_contents($attemptsFile);
    if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Sesiunea a expirat. Reîncărcați pagina.';
    } elseif ($attempts >= 10) {
        $errors[] = 'Prea multe încercări greșite. Ștergeți fișierul storage/framework/instalare-incercari din File Manager pentru a reîncerca.';
    } elseif (($env['DB_PASSWORD'] ?? '') === '' || ! hash_equals((string) $env['DB_PASSWORD'], (string) ($_POST['db_password'] ?? ''))) {
        @file_put_contents($attemptsFile, (string) ($attempts + 1));
        sleep(2);
        $errors[] = 'Parola bazei de date nu corespunde cu DB_PASSWORD din .env.';
    } else {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresa de e-mail a administratorului nu este validă.';
        }
        if ($name === '') {
            $errors[] = 'Completați numele administratorului.';
        }
        if (strlen($password) < 12 || ! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password) || ! preg_match('/\d/', $password)) {
            $errors[] = 'Parola administratorului: minim 12 caractere, cu litere mari, litere mici și cifre.';
        }
        if ($password !== (string) ($_POST['password_confirmation'] ?? '')) {
            $errors[] = 'Parolele nu coincid.';
        }
        if (! $ready) {
            $errors[] = 'Rezolvați mai întâi cerințele marcate cu roșu.';
        }
    }

    if (! $errors) {
        @set_time_limit(600);
        @ignore_user_abort(true);
        try {
            // 1. cheia aplicației (înainte de pornirea Laravel, care o citește din .env)
            if (! str_starts_with((string) ($env['APP_KEY'] ?? ''), 'base64:')) {
                $key = 'base64:'.base64_encode(random_bytes(32));
                $content = (string) file_get_contents($root.'/.env');
                $content = preg_match('/^APP_KEY=.*$/m', $content) ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $content) : "APP_KEY={$key}\n".$content;
                file_put_contents($root.'/.env', $content);
                $log[] = ['ok', 'Cheia aplicației a fost generată.'];
            } else {
                $log[] = ['ok', 'Cheia aplicației există deja.'];
            }
            @unlink($root.'/bootstrap/cache/config.php');

            // 2. pornirea aplicației
            require $root.'/vendor/autoload.php';
            $app = require $root.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            $artisan = function (string $command, array $parameters = []) use (&$log): bool {
                $code = Artisan::call($command, $parameters);
                $output = trim(Artisan::output());
                $log[] = [$code === 0 ? 'ok' : 'error', $command.($code === 0 ? ' — reușit' : ' — EROARE'), $output];

                return $code === 0;
            };

            // 3. tabele și date inițiale
            if (! $artisan('migrate', ['--force' => true])) {
                throw new RuntimeException('Crearea tabelelor a eșuat (detalii mai sus).');
            }
            if (DB::table('roles')->exists()) {
                $log[] = ['ok', 'Datele inițiale există deja — nu se reinstalează.'];
            } elseif (! $artisan('db:seed', ['--force' => true])) {
                throw new RuntimeException('Instalarea datelor inițiale a eșuat (detalii mai sus).');
            }

            // 4. administratorul
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill(['name' => $name, 'password' => Hash::make($password), 'is_active' => true])->save();
            $user->syncRoles(['super_admin']);
            $log[] = ['ok', "Contul de administrator {$email} a fost creat."];

            // 5. imaginile încărcate (public/storage)
            if (is_link(__DIR__.'/storage') || is_dir(__DIR__.'/storage')) {
                $log[] = ['ok', 'Legătura public/storage există deja.'];
            } elseif (function_exists('symlink') && @symlink($root.'/storage/app/public', __DIR__.'/storage')) {
                $log[] = ['ok', 'Legătura pentru imagini (public/storage) a fost creată.'];
            } else {
                $log[] = ['warn', 'Legătura public/storage nu a putut fi creată (funcția symlink este dezactivată). Imaginile încărcate din panou nu vor apărea pe site până nu o creează firma de găzduire: public/storage → ../storage/app/public.'];
            }

            // 6. optimizare
            // „optimize” include și cache-urile Filament și ale iconițelor (comenzile separate există doar în consolă)
            $artisan('optimize');

            @file_put_contents($lock, 'Instalat la '.date('c'));
            @unlink($attemptsFile);
            $done = true;
            $selfDeleted = @unlink(__FILE__);
        } catch (Throwable $e) {
            $log[] = ['error', 'Instalarea s-a oprit: '.$e->getMessage()];
        }
    }
}

$cli = cliPhpPath();
?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Instalare — Another World Cluj</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #0a1033; color: #eef0ff; margin: 0; padding: 24px 16px; line-height: 1.5; }
  main { max-width: 760px; margin: 0 auto; }
  h1 { font-size: 1.6rem; margin: 0 0 4px; } h2 { font-size: 1.15rem; margin: 28px 0 10px; }
  .muted { color: #a9b0d6; }
  .card { background: #141c52; border: 1px solid #2a3480; border-radius: 12px; padding: 18px; margin-top: 16px; }
  ul.checks { list-style: none; padding: 0; margin: 0; } ul.checks li { padding: 4px 0; }
  .ok { color: #4ee6a8; } .error { color: #ff7a93; } .warn { color: #ffc85c; }
  label { display: block; margin-top: 12px; font-weight: 600; }
  input { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 10px 12px; border-radius: 8px; border: 1px solid #3a4596; background: #0a1033; color: #eef0ff; font-size: 1rem; }
  button, a.btn { display: inline-block; margin-top: 18px; padding: 12px 22px; border: 0; border-radius: 999px; background: #e61c4a; color: #fff; font-size: 1rem; font-weight: 600; cursor: pointer; text-decoration: none; }
  button[disabled] { opacity: .5; cursor: not-allowed; }
  pre { white-space: pre-wrap; background: #05040f; padding: 10px; border-radius: 8px; font-size: .8rem; color: #a9b0d6; max-height: 220px; overflow: auto; }
  code { background: #05040f; padding: 2px 6px; border-radius: 4px; word-break: break-all; }
  .alert { padding: 12px 14px; border-radius: 8px; background: rgb(255 122 147 / .15); border: 1px solid #ff7a93; margin-top: 16px; }
</style>
</head>
<body>
<main>
    <h1>Instalare Another World Cluj</h1>
    <p class="muted">Pagina pregătește baza de date și creează contul de administrator. Se folosește o singură dată.</p>

<?php if ($installed && ! $done) { ?>
    <div class="card">
        <p><strong>Aplicația este deja instalată.</strong> Din motive de siguranță, această pagină nu mai face nimic.</p>
        <p>Ștergeți fișierul <code>instalare.php</code> din File Manager (folderul public_html sau anotherworldcluj/public).</p>
        <a class="btn" href="/admin">Mergi la panoul de administrare</a>
    </div>
<?php } elseif ($done) { ?>
    <div class="card">
        <h2 class="ok">Instalarea s-a încheiat cu succes</h2>
        <ul class="checks">
            <?php foreach ($log as $entry) { ?>
                <li class="<?= h($entry[0]) ?>"><?= $entry[0] === 'ok' ? '✓' : ($entry[0] === 'warn' ? '!' : '✗') ?> <?= h($entry[1]) ?></li>
            <?php } ?>
        </ul>
        <?php if (empty($selfDeleted)) { ?>
            <p class="warn"><strong>Ștergeți acum fișierul <code>instalare.php</code> din File Manager (folderul public_html sau anotherworldcluj/public).</strong> (Pagina este deja dezactivată, dar fișierul nu a putut fi șters automat.)</p>
        <?php } else { ?>
            <p class="muted">Pagina de instalare s-a șters singură.</p>
        <?php } ?>

        <h2>Ultimul pas: cron-ul permanent</h2>
        <p>cPanel → <strong>Cron Jobs</strong> → <em>Once Per Minute</em> → la <strong>Command</strong> copiați:</p>
        <p><code>cd <?= h($root) ?> &amp;&amp; <?= h($cli ?? '/usr/local/bin/php') ?> artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code></p>
        <?php if (! $cli) { ?><p class="warn">Nu am putut detecta calea PHP 8.3 pentru cron; dacă cron-ul nu funcționează, cereți firmei de găzduire „calea către PHP 8.3 CLI”.</p><?php } ?>

        <h2>Apoi</h2>
        <p>Intrați în panou cu adresa și parola alese. La prima autentificare vi se cere configurarea autentificării în doi pași
            (Google Authenticator / Microsoft Authenticator) — <strong>notați codurile de recuperare</strong>.</p>
        <a class="btn" href="/admin">Deschide panoul de administrare</a>
    </div>
<?php } else { ?>
    <div class="card">
        <h2 style="margin-top:0">1. Verificarea serverului</h2>
        <ul class="checks">
            <?php foreach ($checks as $check) { ?>
                <li class="<?= $check[0] ? 'ok' : 'error' ?>"><?= $check[0] ? '✓' : '✗' ?> <?= h($check[1]) ?><?php if (! $check[0] && isset($check[2])) { ?> — <span class="muted"><?= h($check[2]) ?></span><?php } ?></li>
            <?php } ?>
        </ul>
        <?php if (! $ready) { ?><p class="warn">Rezolvați punctele marcate cu ✗, apoi reîncărcați pagina.</p><?php } ?>
    </div>

    <?php if ($errors) { ?><div class="alert"><?php foreach ($errors as $error) { ?><div><?= h($error) ?></div><?php } ?></div><?php } ?>
    <?php if ($log) { ?>
        <div class="card">
            <h2 style="margin-top:0" class="error">Instalarea nu s-a încheiat</h2>
            <ul class="checks">
                <?php foreach ($log as $entry) { ?>
                    <li class="<?= h($entry[0]) ?>"><?= $entry[0] === 'ok' ? '✓' : ($entry[0] === 'warn' ? '!' : '✗') ?> <?= h($entry[1]) ?><?php if (! empty($entry[2]) && $entry[0] !== 'ok') { ?><pre><?= h($entry[2]) ?></pre><?php } ?></li>
                <?php } ?>
            </ul>
            <p class="muted">Corectați cauza și apăsați din nou „Instalează” — pașii deja făcuți nu se repetă.</p>
        </div>
    <?php } ?>

    <form method="post" class="card" autocomplete="off">
        <h2 style="margin-top:0">2. Confirmarea accesului</h2>
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <label for="db_password">Parola bazei de date (valoarea DB_PASSWORD din .env)</label>
        <input id="db_password" name="db_password" type="password" required>

        <h2>3. Contul de administrator</h2>
        <label for="name">Nume și prenume</label>
        <input id="name" name="name" value="<?= h($_POST['name'] ?? '') ?>" required>
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" value="<?= h($_POST['email'] ?? '') ?>" required>
        <label for="password">Parolă (minim 12 caractere, litere mari, mici și cifre)</label>
        <input id="password" name="password" type="password" minlength="12" required>
        <label for="password_confirmation">Repetați parola</label>
        <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" required>

        <button type="submit" <?= $ready ? '' : 'disabled' ?>>Instalează</button>
        <p class="muted">Durează de obicei sub un minut. Nu închideți pagina.</p>
    </form>
<?php } ?>
</main>
</body>
</html>
