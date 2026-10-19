<?php

namespace Tests\Feature\System;

use App\Domain\System\BackupManager;
use App\Domain\System\Diagnostics;
use App\Livewire\Admin\System\SystemBackups;
use App\Livewire\Admin\System\SystemUpdates;
use App\Support\Settings;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class SystemScreensTest extends TestCase
{
    use RefreshDatabase;

    private array $backups = [];

    protected function tearDown(): void
    {
        foreach ($this->backups as $name) {
            BackupManager::make()->delete($name);
        }
        parent::tearDown();
    }

    public function test_system_screens_are_checked_on_the_server(): void
    {
        $reception = $this->staff('reception');
        foreach (['admin.system.diagnostics', 'admin.system.backups', 'admin.system.updates', 'admin.system.import'] as $route) {
            $this->signIn($reception)->get(route($route))->assertForbidden();
        }
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        foreach (['admin.system.diagnostics', 'admin.system.backups', 'admin.system.updates', 'admin.system.import'] as $route) {
            $this->signIn($admin)->get(route($route))->assertOk();
        }
        $this->get(route('admin.system.backups.download', '20260101-000000-manual-abcd'))->assertNotFound();
        $this->signIn($reception)->get(route('admin.system.backups.download', '20260101-000000-manual-abcd'))->assertForbidden();
    }

    public function test_maintenance_bypass_cookie_is_not_encrypted(): void
    {
        // Set from a Livewire response during an update; Laravel reads it before decrypting cookies.
        $this->assertTrue(app(EncryptCookies::class)->isDisabled('laravel_maintenance'));
    }

    public function test_diagnostics_report_missing_cron_and_unconfigured_parts(): void
    {
        $checks = collect(Diagnostics::make()->checks())->keyBy('key');
        $this->assertSame('error', $checks['cron']['status']);
        $this->assertSame('warning', $checks['backup']['status']);
        $this->assertSame('ok', $checks['migrations']['status']);

        Settings::put('system.cron_last_run', now()->toIso8601String());
        $checks = collect(Diagnostics::make()->checks())->keyBy('key');
        $this->assertSame('ok', $checks['cron']['status']);
    }

    public function test_error_lines_show_time_and_class_only(): void
    {
        $log = storage_path('logs/diagnostics-test.log');
        file_put_contents($log, '['.now()->format('Y-m-d H:i:s').'] testing.ERROR: SQLSTATE insert into patients values (Ana Secret) {"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 23000): secret"}'."\n");
        try {
            [$count, $recent] = Diagnostics::make()->recentErrors();
        } finally {
            unlink($log);
        }
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertStringContainsString('QueryException', implode(' ', $recent));
        $this->assertStringNotContainsString('Secret', implode(' ', $recent));
    }

    public function test_backups_screen_creates_lists_and_downloads_a_verified_backup(): void
    {
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        Livewire::actingAs($admin)->test(SystemBackups::class)->call('create')->assertSet('error', null);
        $backup = BackupManager::make()->all()[0];
        $this->backups[] = $backup['name'];
        $this->assertTrue($backup['verified']);

        $response = $this->signIn($admin)->get(route('admin.system.backups.download', $backup['name']));
        $response->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.backup_downloaded']);

        Livewire::actingAs($admin)->test(SystemBackups::class)
            ->set('scheduleEnabled', true)->set('scheduleTime', '03:15')->call('saveSchedule');
        $this->assertSame(['enabled' => true, 'time' => '03:15'], Settings::get('system.backup_schedule'));
    }

    public function test_updates_screen_rejects_an_unsigned_package_and_shows_missing_keys(): void
    {
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        config(['optica.updates.keys_file' => '/nonexistent']);
        Livewire::actingAs($admin)->test(SystemUpdates::class)->assertSee(__('system.updates.not_configured'));

        $pair = sodium_crypto_sign_keypair();
        config(['optica.updates.public_keys' => [base64_encode(sodium_crypto_sign_publickey($pair))]]);
        $path = tempnam(sys_get_temp_dir(), 'pkg');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode(['version' => '99.0.0', 'files' => []]));
        $zip->addFromString('manifest.sig', base64_encode(str_repeat('x', 64)));
        $zip->close();

        Livewire::actingAs($admin)->test(SystemUpdates::class)
            ->set('package', UploadedFile::fake()->createWithContent('optica-99.0.0-update.zip', (string) file_get_contents($path)))
            ->call('checkPackage')
            ->assertSet('packageErrors', [__('system.updates.errors.signature')]);
        $this->assertSame('rejected', DB::table('update_runs')->value('status'));
        $this->assertSame(config('optica.version'), trim((string) file_get_contents(base_path('VERSION'))));
    }
}
