<?php

namespace Tests\Support;

use App\Support\Recovery\RecoveryKit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;
use ZipArchive;

/**
 * Backups, restores and updates drop and recreate tables and replace files, so these tests run
 * against a separate scratch database (optica_scratch) and a throw-away application directory,
 * never against the real project or the transactional test database.
 */
trait SystemSandbox
{
    protected string $sandbox;

    protected string $secretKey;

    protected function setUpSandbox(): void
    {
        $database = env('DB_SCRATCH_DATABASE', 'optica_scratch');
        try {
            new PDO('mysql:host='.config('database.connections.mysql.host').';port='.config('database.connections.mysql.port').';dbname='.$database,
                config('database.connections.mysql.username'), config('database.connections.mysql.password'));
        } catch (Throwable) {
            $this->markTestSkipped('Scratch database '.$database.' is not available.');
        }
        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->sandbox = sys_get_temp_dir().'/optica-sandbox-'.bin2hex(random_bytes(4));
        foreach (['app', 'database/migrations', 'storage/app/private', 'storage/framework', 'public/uploads', 'bootstrap/cache'] as $dir) {
            mkdir($this->sandbox.'/'.$dir, 0755, true);
        }
        file_put_contents($this->sandbox.'/VERSION', "1.0.0\n");
        file_put_contents($this->sandbox.'/app/Kept.php', "<?php // kept\n");
        file_put_contents($this->sandbox.'/app/Changed.php', "<?php // version 1.0.0\n");
        file_put_contents($this->sandbox.'/app/Removed.php', "<?php // only in 1.0.0\n");
        $c = config('database.connections.mysql');
        file_put_contents($this->sandbox.'/.env', "DB_HOST={$c['host']}\nDB_PORT={$c['port']}\nDB_DATABASE=$database\nDB_USERNAME={$c['username']}\nDB_PASSWORD=\"{$c['password']}\"\n");
        file_put_contents($this->sandbox.'/release.json', json_encode(['version' => '1.0.0', 'files' => [
            'app/Kept.php' => hash_file('sha256', $this->sandbox.'/app/Kept.php'),
            'app/Changed.php' => hash_file('sha256', $this->sandbox.'/app/Changed.php'),
            'app/Removed.php' => hash_file('sha256', $this->sandbox.'/app/Removed.php'),
        ]]));
        config(['optica.version' => '1.0.0']);

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        config(['optica.updates.public_keys' => [base64_encode(sodium_crypto_sign_publickey($pair))]]);
    }

    protected function tearDownSandbox(): void
    {
        if (isset($this->sandbox) && is_dir($this->sandbox)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->sandbox);
        }
    }

    protected function kit(): RecoveryKit
    {
        return new RecoveryKit($this->sandbox);
    }

    /**
     * A package from version 1.0.0 to $version.
     *
     * @param  array<string, string>  $files  relative path => contents
     * @param  array<string, mixed>  $manifestOverrides
     */
    protected function package(string $version, array $files, array $manifestOverrides = [], ?string $secret = null, array $extraEntries = []): string
    {
        $files += ['VERSION' => $version."\n"];
        $manifest = array_replace([
            'version' => $version, 'min_from_version' => '1.0.0', 'min_php' => '8.3.0',
            'changelog' => ['ro' => 'Versiune de test', 'hu' => 'Tesztverzió'],
            'files' => array_map(fn ($c) => hash('sha256', $c), $files),
        ], $manifestOverrides);
        $json = json_encode($manifest);
        $path = sys_get_temp_dir().'/optica-pkg-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', $json);
        $zip->addFromString('manifest.sig', base64_encode(sodium_crypto_sign_detached($json, $secret ?? $this->secretKey)));
        foreach ($files as $relative => $contents) {
            $zip->addFromString('files/'.$relative, $contents);
        }
        foreach ($extraEntries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
