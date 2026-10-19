<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\EnvBootstrap;
use App\Support\Install\InstallState;
use App\Support\Recovery\RecoveryKit;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use PDO;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * Web installer (INS-01, INS-02), in RO and HU. Protected by a one-time token written to
 * storage/app/install-token.txt, locked for good by storage/app/installed.lock at the end.
 * Plain forms (no Livewire): it must work before the database exists.
 */
class InstallController extends Controller
{
    public const STEPS = ['requirements', 'database', 'migrate', 'admin', 'settings', 'finish'];

    public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'intl', 'sodium', 'zip', 'gd', 'fileinfo', 'openssl', 'ctype', 'curl', 'dom', 'tokenizer', 'xml'];

    private function boot(Request $request): void
    {
        $locale = $request->query('lang', $request->session()->get('install.locale', 'ro'));
        $locale = in_array($locale, ['ro', 'hu'], true) ? $locale : 'ro';
        $request->session()->put('install.locale', $locale);
        app()->setLocale($locale);
    }

    private function authorised(Request $request): bool
    {
        return $request->session()->get('install.authorised') === true;
    }

    public function index(Request $request): View|RedirectResponse
    {
        $this->boot($request);
        if ($this->authorised($request)) {
            return redirect()->route('install.step', $request->session()->get('install.step', 'requirements'));
        }
        InstallState::token();

        return view('install.token', ['tokenFile' => 'storage/app/install-token.txt']);
    }

    public function token(Request $request): RedirectResponse
    {
        $this->boot($request);
        $request->validate(['token' => ['required', 'string', 'max:100']]);
        if (! hash_equals(InstallState::token(), trim((string) $request->input('token')))) {
            return back()->withErrors(['token' => __('install.token.invalid')]);
        }
        $request->session()->regenerate();
        $request->session()->put('install.authorised', true);

        return redirect()->route('install.step', 'requirements');
    }

    public function step(Request $request, string $step): View|RedirectResponse
    {
        $this->boot($request);
        abort_unless(in_array($step, self::STEPS, true), 404);
        if (! $this->authorised($request)) {
            return redirect()->route('install.index');
        }
        if (($redirect = $this->guard($request, $step)) !== null) {
            return $redirect;
        }
        $request->session()->put('install.step', $step);
        $data = ['step' => $step, 'steps' => self::STEPS];

        return match ($step) {
            'requirements' => view('install.requirements', $data + ['checks' => $this->requirements($request)]),
            'database' => view('install.database', $data + ['env' => (new RecoveryKit(base_path()))->env()]),
            'migrate' => view('install.migrate', $data),
            'admin' => view('install.admin', $data + ['hasAdmin' => $this->hasSuperadmin()]),
            'settings' => view('install.settings', $data + ['url' => $request->getSchemeAndHttpHost().$request->getBasePath(), 'timezones' => \DateTimeZone::listIdentifiers(\DateTimeZone::EUROPE),
                // A host starting with "test." is almost certainly the test installation.
                'looksLikeTest' => str_starts_with($request->getHost(), 'test.') || str_starts_with($request->getHost(), 'staging.')]),
            'finish' => $this->finish($request, $data),
        };
    }

    /** A step is reachable only when the previous ones are really done. */
    private function guard(Request $request, string $step): ?RedirectResponse
    {
        $index = array_search($step, self::STEPS, true);
        if ($index >= 1 && ! $this->requirementsPass($request)) {
            return redirect()->route('install.step', 'requirements');
        }
        if ($index >= 2 && ! $this->databaseReady()) {
            return redirect()->route('install.step', 'database');
        }
        if ($index >= 3 && ! $this->migrated()) {
            return redirect()->route('install.step', 'migrate');
        }
        if ($index >= 4 && ! $this->hasSuperadmin()) {
            return redirect()->route('install.step', 'admin');
        }
        if ($index >= 5 && ! $request->session()->get('install.settings_done')) {
            return redirect()->route('install.step', 'settings');
        }

        return null;
    }

    // ----- requirements -----------------------------------------------------------------------

    /** @return list<array{label: string, ok: bool, level: string, detail: string}> */
    private function requirements(Request $request): array
    {
        $checks = [];
        $add = function (string $label, bool $ok, string $detail = '', string $level = 'error') use (&$checks) {
            $checks[] = ['label' => $label, 'ok' => $ok, 'level' => $ok ? 'ok' : $level, 'detail' => $detail];
        };
        $add(__('install.req.php', ['version' => '8.3']), version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION);
        foreach (self::EXTENSIONS as $ext) {
            $add(__('install.req.extension', ['name' => $ext]), extension_loaded($ext));
        }
        foreach (['storage', 'storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache', 'public/uploads'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $add(__('install.req.writable', ['path' => $dir]), is_dir($path) && is_writable($path));
        }
        $add(__('install.req.writable', ['path' => '.env']), is_writable(base_path('.env')));
        $free = @disk_free_space(base_path());
        $add(__('install.req.disk'), $free === false || $free > 500 * 1024 * 1024, $free === false ? '?' : round($free / 1073741824, 1).' GB', 'warning');
        $memory = ini_get('memory_limit');
        $bytes = $memory === '-1' ? PHP_INT_MAX : $this->bytes((string) $memory);
        $add(__('install.req.memory'), $bytes >= 128 * 1024 * 1024, (string) $memory, 'warning');
        $upload = (string) ini_get('upload_max_filesize');
        $add(__('install.req.upload'), $this->bytes($upload) >= 64 * 1024 * 1024, $upload, 'warning');
        $add(__('install.req.https'), $request->isSecure(), '', 'warning');
        // The document root must be public/: otherwise .env and the code would be reachable from the web.
        // The alternative is the root .htaccess, which routes every request into public/.
        $docRoot = realpath((string) $request->server('DOCUMENT_ROOT')) ?: '';
        $viaRootHtaccess = $docRoot === realpath(base_path()) && str_starts_with((string) $request->server('SCRIPT_NAME'), '/public/');
        if ($docRoot === realpath(public_path())) {
            $add(__('install.req.docroot'), true, 'public/');
        } elseif ($viaRootHtaccess) {
            $add(__('install.req.docroot'), false, __('install.req.docroot_htaccess'), 'warning');
        } else {
            $add(__('install.req.docroot'), $docRoot !== realpath(base_path()), __('install.req.docroot_hint'), $docRoot === realpath(base_path()) ? 'error' : 'warning');
        }

        return $checks;
    }

    private function requirementsPass(Request $request): bool
    {
        return collect($this->requirements($request))->every(fn ($c) => $c['ok'] || $c['level'] !== 'error');
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    // ----- database ---------------------------------------------------------------------------

    public function saveDatabase(Request $request): RedirectResponse
    {
        $this->boot($request);
        abort_unless($this->authorised($request), 403);
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:190'],
        ]);
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $data['host'], $data['port'], $data['database']), $data['username'], (string) ($data['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        } catch (Throwable $e) {
            return back()->withInput($request->except('password'))->withErrors(['database' => __('install.db.connect_failed', ['error' => Str::limit($e->getMessage(), 160)])]);
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        $numeric = preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version);
        if (version_compare($numeric, $isMaria ? '10.6.0' : '8.0.0', '<')) {
            return back()->withInput($request->except('password'))->withErrors(['database' => __('install.db.version', ['version' => $version])]);
        }
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        // An empty database, or one of this platform (an installation that was interrupted).
        if ($tables && ! in_array('migrations', $tables, true)) {
            return back()->withInput($request->except('password'))->withErrors(['database' => __('install.db.not_empty')]);
        }
        try {
            $pdo->exec('CREATE TABLE optica_install_check (id INT PRIMARY KEY)');
            $pdo->exec('DROP TABLE optica_install_check');
        } catch (Throwable) {
            return back()->withInput($request->except('password'))->withErrors(['database' => __('install.db.privileges')]);
        }
        EnvBootstrap::write(base_path(), ['DB_CONNECTION' => 'mysql', 'DB_HOST' => $data['host'], 'DB_PORT' => (string) $data['port'],
            'DB_DATABASE' => $data['database'], 'DB_USERNAME' => $data['username'], 'DB_PASSWORD' => (string) ($data['password'] ?? '')]);
        $this->useDatabase($data);

        return redirect()->route('install.step', 'migrate')->with('status', __('install.db.saved', ['version' => $version]));
    }

    private function useDatabase(array $data): void
    {
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            config(['database.connections.mysql.'.$key => $data[$key] ?? '']);
        }
        config(['database.default' => 'mysql']);
        DB::purge('mysql');
    }

    private function databaseReady(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function migrated(): bool
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }
            $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);

            return array_diff($files, DB::table('migrations')->pluck('migration')->all()) === [] && Role::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function migrate(Request $request): RedirectResponse
    {
        $this->boot($request);
        abort_unless($this->authorised($request) && $this->databaseReady(), 403);
        @set_time_limit(300);
        try {
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new \RuntimeException(trim(Artisan::output()));
            }
            // Roles, the content of the current site, message and clinical templates. Never demo data.
            Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        } catch (Throwable $e) {
            return back()->withErrors(['migrate' => __('install.migrate.failed', ['error' => Str::limit($e->getMessage(), 300)])]);
        }

        return redirect()->route('install.step', 'admin')->with('status', __('install.migrate.done'));
    }

    // ----- administrator ----------------------------------------------------------------------

    private function hasSuperadmin(): bool
    {
        try {
            return User::whereHas('roles', fn ($q) => $q->where('key', 'superadmin'))->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function saveAdmin(Request $request): RedirectResponse
    {
        $this->boot($request);
        abort_unless($this->authorised($request) && $this->migrated(), 403);
        if ($this->hasSuperadmin()) {
            return redirect()->route('install.step', 'settings');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
            'locale' => ['required', 'in:ro,hu'],
        ], [], ['name' => __('install.admin.name'), 'email' => __('install.admin.email'), 'password' => __('install.admin.password')]);
        $user = User::create(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password'], 'locale' => $data['locale'], 'all_locations' => true]);
        $user->roles()->attach(Role::where('key', 'superadmin')->value('id'));

        return redirect()->route('install.step', 'settings')->with('status', __('install.admin.created'));
    }

    // ----- settings ---------------------------------------------------------------------------

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->boot($request);
        abort_unless($this->authorised($request) && $this->hasSuperadmin(), 403);
        $data = $request->validate([
            'url' => ['required', 'url:https,http', 'max:190'],
            'timezone' => ['required', 'timezone:all'],
            'mail_host' => ['nullable', 'string', 'max:190'],
            'mail_port' => ['nullable', 'required_with:mail_host', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:190'],
            'mail_password' => ['nullable', 'string', 'max:190'],
            'mail_scheme' => ['nullable', 'in:smtp,smtps'],
            'mail_from' => ['nullable', 'required_with:mail_host', 'email', 'max:190'],
            'action' => ['nullable', 'in:test,save'],
            'test_site' => ['nullable', 'boolean'],
            'test_recipients' => ['nullable', 'string', 'max:500'],
            'demo_data' => ['nullable', 'boolean'],
        ]);
        $testSite = (bool) ($data['test_site'] ?? false);
        $recipients = collect(preg_split('/[,;\n]+/', (string) ($data['test_recipients'] ?? '')))->map(fn ($r) => trim($r))->filter()->values();
        foreach ($recipients as $r) {
            if (! filter_var($r, FILTER_VALIDATE_EMAIL) && strlen(preg_replace('/\D+/', '', $r)) < 9) {
                return back()->withInput($request->except('mail_password'))->withErrors(['test_recipients' => __('install.settings.test_recipients_invalid', ['value' => $r])]);
            }
        }
        $smtp = ! empty($data['mail_host']);
        if ($smtp && ($data['action'] ?? 'save') === 'test') {
            // Connection and authentication only; no message is sent.
            try {
                $transport = new EsmtpTransport($data['mail_host'], (int) $data['mail_port'], ($data['mail_scheme'] ?? 'smtp') === 'smtps');
                if (! empty($data['mail_username'])) {
                    $transport->setUsername($data['mail_username']);
                    $transport->setPassword((string) ($data['mail_password'] ?? ''));
                }
                $transport->start();
                $transport->stop();
            } catch (Throwable $e) {
                return back()->withInput($request->except('mail_password'))->withErrors(['mail_host' => __('install.settings.smtp_failed', ['error' => Str::limit($e->getMessage(), 160)])]);
            }

            return back()->withInput($request->except('mail_password'))->with('status', __('install.settings.smtp_ok'));
        }
        EnvBootstrap::write(base_path(), array_filter([
            'APP_URL' => rtrim($data['url'], '/'),
            'CLINIC_TIMEZONE' => $data['timezone'],
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            // Without SMTP the email stays "not configured": nothing is pretended to be sent.
            'MAIL_MAILER' => $smtp ? 'smtp' : 'log',
            'MAIL_HOST' => $smtp ? $data['mail_host'] : null,
            'MAIL_PORT' => $smtp ? (string) $data['mail_port'] : null,
            'MAIL_USERNAME' => $smtp ? (string) ($data['mail_username'] ?? '') : null,
            'MAIL_PASSWORD' => $smtp ? (string) ($data['mail_password'] ?? '') : null,
            'MAIL_SCHEME' => $smtp ? ($data['mail_scheme'] ?? 'smtp') : null,
            'MAIL_FROM_ADDRESS' => $smtp ? $data['mail_from'] : null,
            'OPTICA_TEST_SITE' => $testSite ? 'true' : 'false',
            'OPTICA_TEST_RECIPIENTS' => $testSite ? $recipients->implode(',') : '',
        ], fn ($v) => $v !== null));
        // Fictitious patients and appointments to try the CRM; only ever on a test installation.
        if ($testSite && ($data['demo_data'] ?? false)) {
            config(['optica.test_site' => true]);
            try {
                Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
            } catch (Throwable $e) {
                return back()->withInput($request->except('mail_password'))->withErrors(['demo_data' => Str::limit($e->getMessage(), 200)]);
            }
        }
        $request->session()->put('install.settings_done', true);

        return redirect()->route('install.step', 'finish');
    }

    // ----- finish -----------------------------------------------------------------------------

    private function finish(Request $request, array $data): View
    {
        // The recovery token is shown once; only its hash stays on the server.
        $token = (new RecoveryKit(base_path()))->newRecoveryToken();
        InstallState::lock(config('optica.version'));
        $request->session()->forget(['install.authorised', 'install.step', 'install.settings_done']);
        $php = str_contains(PHP_BINARY, 'fpm') || str_contains(PHP_BINARY, 'lsphp') || PHP_BINARY === '' ? '/usr/local/bin/php' : PHP_BINARY;

        return view('install.finish', $data + [
            'recoveryToken' => $token,
            'cron' => '* * * * * '.$php.' '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
        ]);
    }
}
