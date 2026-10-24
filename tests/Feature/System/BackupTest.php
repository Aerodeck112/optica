<?php

namespace Tests\Feature\System;

use App\Modules\Core\Backup\BackupService;
use App\Modules\Crm\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aw.backup.path' => storage_path('framework/testing/backups-'.getmypid())]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('aw.backup.path'));
        parent::tearDown();
    }

    public function test_backup_restores_data_including_tricky_strings(): void
    {
        Customer::factory()->create(['first_name' => "O'Brien; (test)\nlinie nouă", 'last_name' => 'Ștefănescu \\ backslash', 'email' => 'a@example.test']);
        Customer::factory()->count(250)->create();
        $backups = app(BackupService::class);

        $result = $backups->create('database');
        $this->assertTrue($backups->verify($result['path'])['ok']);

        Customer::query()->delete();
        Customer::factory()->create(['email' => 'dupa-backup@example.test']);
        $backups->restore($result['path']);

        $this->assertSame(251, Customer::query()->count());
        $restored = Customer::query()->where('email', 'a@example.test')->first();
        $this->assertSame("O'Brien; (test)\nlinie nouă", $restored->first_name);
        $this->assertSame('Ștefănescu \\ backslash', $restored->last_name);
        $this->assertFalse(Customer::query()->where('email', 'dupa-backup@example.test')->exists());
    }

    public function test_encrypted_backup_needs_the_password_and_tampering_is_detected(): void
    {
        config(['aw.backup.password' => 'parola-de-test-lunga']);
        Customer::factory()->count(3)->create();
        $backups = app(BackupService::class);
        $path = $backups->create('database')['path'];

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertSame(ZipArchive::EM_AES_256, $zip->statName('database.sql')['encryption_method']);
        $zip->close();

        config(['aw.backup.password' => 'alta-parola']);
        try {
            $backups->verify($path);
            $this->fail('Fără parola corectă arhiva nu se poate citi.');
        } catch (\RuntimeException) {
        }

        config(['aw.backup.password' => 'parola-de-test-lunga']);
        $zip->open($path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['tables']['customers'] = 99;
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        $this->assertFalse($backups->verify($path)['ok']);
    }

    public function test_old_backups_are_pruned(): void
    {
        config(['aw.backup.keep_daily' => 2]);
        $backups = app(BackupService::class);
        foreach (range(1, 3) as $i) {
            $this->travel(1)->seconds();
            $backups->create('database');
        }

        $this->assertCount(2, $backups->list());
    }
}
