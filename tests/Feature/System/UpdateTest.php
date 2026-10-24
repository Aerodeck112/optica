<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Modules\Core\Models\AppUpdate;
use App\Modules\Core\Updates\UpdateService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class UpdateTest extends TestCase
{
    use DatabaseMigrations;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = storage_path('framework/testing/update-'.getmypid());
        File::ensureDirectoryExists($this->sandbox.'/app/resources');
        File::ensureDirectoryExists($this->sandbox.'/public');
        file_put_contents($this->sandbox.'/app/VERSION', "1.0.0\n");
        file_put_contents($this->sandbox.'/app/resources/vechi.txt', 'versiunea veche');
        file_put_contents($this->sandbox.'/app/.env', 'APP_KEY=secret');
        config([
            'aw.updates.app_root' => $this->sandbox.'/app',
            'aw.updates.public_root' => $this->sandbox.'/public',
            'aw.updates.rebuild_caches' => false,
            'aw.backup.path' => $this->sandbox.'/backups',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        File::deleteDirectory(storage_path('app/private/updates'));
        parent::tearDown();
    }

    private function package(array $files, array $manifest = []): string
    {
        $path = $this->sandbox.'/pachet-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('aw-update.json', json_encode($manifest + ['format' => 1, 'version' => '1.0.1', 'description' => 'Test', 'migrate' => false]));
        foreach ($files as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return $path;
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_package_is_applied_with_backup_and_can_be_rolled_back(): void
    {
        $user = $this->admin();
        $service = app(UpdateService::class);
        $zip = $this->package([
            'app/resources/vechi.txt' => 'versiunea nouă',
            'app/resources/nou.txt' => 'fișier nou',
            'public/build/site.css' => 'body{}',
        ], ['delete' => []]);

        $update = $service->apply($zip, $user);

        $this->assertSame('applied', $update->status);
        $this->assertSame('1.0.1', $service->currentVersion());
        $this->assertSame('versiunea nouă', file_get_contents($this->sandbox.'/app/resources/vechi.txt'));
        $this->assertFileExists($this->sandbox.'/public/build/site.css');
        $this->assertNotNull($update->database_backup);
        $this->assertSame('APP_KEY=secret', file_get_contents($this->sandbox.'/app/.env'));

        $service->rollback($update, $user);

        $this->assertSame('rolled_back', $update->fresh()->status);
        $this->assertSame('1.0.0', $service->currentVersion());
        $this->assertSame('versiunea veche', file_get_contents($this->sandbox.'/app/resources/vechi.txt'));
        $this->assertFileDoesNotExist($this->sandbox.'/app/resources/nou.txt');
        $this->assertFileDoesNotExist($this->sandbox.'/public/build/site.css');
    }

    public function test_packages_cannot_escape_or_touch_installation_files(): void
    {
        $service = app(UpdateService::class);
        foreach ([
            ['app/../../etc/passwd' => 'x'],
            ['app/.env' => 'APP_KEY=furat'],
            ['app/storage/app/private/x.php' => 'x'],
            ['public/index.php' => '<?php echo 1;'],
            ['altceva/fisier.txt' => 'x'],
        ] as $files) {
            try {
                $service->inspect($this->package($files));
                $this->fail('Pachetul ar fi trebuit respins: '.array_key_first($files));
            } catch (RuntimeException) {
            }
        }

        $this->expectException(RuntimeException::class);
        $service->inspect($this->package(['app/a.txt' => 'x'], ['format' => 2]));
    }

    public function test_only_super_admin_sees_the_updates_page(): void
    {
        $admin = $this->admin();
        $admin->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');

        $this->actingAs($admin)->get('/admin/updates')->assertOk()->assertSee('Versiunea instalată');
        $this->actingAs($reception)->get('/admin/updates')->assertForbidden();
        $this->assertSame(0, AppUpdate::query()->count());
    }
}
