<?php

namespace Tests\Feature\System;

use App\Domain\System\BackupManager;
use App\Domain\System\ReleaseBuilder;
use App\Domain\System\UpdatePackage;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SystemSandbox;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use SystemSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandbox();
    }

    protected function tearDown(): void
    {
        $this->tearDownSandbox();
        parent::tearDown();
    }

    public function test_dump_and_restore_keep_difficult_values_exactly(): void
    {
        $values = ["Linia 1\nLinia 2\r\nquote ' and \" and \\ backslash", 'Șțăîâ őű 🙂', "nul\0byte", '', ';) -- not a comment'];
        Schema::create('dump_check', function ($t) {
            $t->id();
            $t->text('value')->nullable();
            $t->binary('raw')->nullable();
        });
        foreach ($values as $v) {
            DB::table('dump_check')->insert(['value' => $v, 'raw' => random_bytes(16)]);
        }
        DB::table('dump_check')->insert(['value' => null, 'raw' => null]);
        $before = DB::table('dump_check')->orderBy('id')->get()->map(fn ($r) => [$r->value, bin2hex((string) $r->raw)])->all();

        $kit = $this->kit();
        $file = $this->sandbox.'/dump.sql.gz';
        $info = $kit->dumpDatabase(DB::connection()->getPdo(), $file);
        $this->assertSame($info, $kit->verifyDump($file));

        DB::table('dump_check')->delete();
        Schema::create('created_after_backup', fn ($t) => $t->id());
        $kit->restoreDatabase(DB::connection()->getPdo(), $file);

        $after = DB::table('dump_check')->orderBy('id')->get()->map(fn ($r) => [$r->value, bin2hex((string) $r->raw)])->all();
        $this->assertSame($before, $after);
        $this->assertFalse(Schema::hasTable('created_after_backup'));

        // A truncated dump is never restored.
        $truncated = $this->sandbox.'/truncated.sql.gz';
        $lines = gzfile($file);
        file_put_contents($truncated, gzencode(implode('', array_slice($lines, 0, (int) (count($lines) / 2)))));
        $this->assertNull($kit->verifyDump($truncated));
        $this->expectException(\RuntimeException::class);
        $kit->restoreDatabase(DB::connection()->getPdo(), $truncated);
    }

    public function test_backup_is_verified_and_restore_brings_back_data_and_files_with_a_safety_copy(): void
    {
        $user = User::factory()->create();
        $patient = Patient::create(['code' => 'P900200', 'first_name' => 'Ioana', 'last_name' => 'Fictiv', 'locale' => 'ro']);
        file_put_contents($this->sandbox.'/public/uploads/photo.webp', 'image-v1');
        @mkdir($this->sandbox.'/storage/app/private/patient-documents', 0755, true);
        file_put_contents($this->sandbox.'/storage/app/private/patient-documents/doc.pdf', 'pdf-v1');

        $backups = new BackupManager($this->kit());
        $manifest = $backups->create('manual', $user);
        $this->assertTrue($manifest['verified']);
        $this->assertTrue($backups->verify($manifest['name']));
        $zip = new ZipArchive;
        $zip->open($backups->dir($manifest['name']).'/files.zip');
        $this->assertNotFalse($zip->locateName('public/uploads/photo.webp'));
        $this->assertNotFalse($zip->locateName('storage/app/private/patient-documents/doc.pdf'));
        // Backups never contain other backups.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString('backups/', $zip->getNameIndex($i));
        }
        $zip->close();

        $patient->delete();
        file_put_contents($this->sandbox.'/public/uploads/photo.webp', 'image-v2');
        $safety = $backups->restore($manifest['name'], $user);

        $this->assertNotNull(Patient::find($patient->id));
        $this->assertSame('image-v1', file_get_contents($this->sandbox.'/public/uploads/photo.webp'));
        $this->assertSame('pre_restore', $safety['type']);
        $this->assertTrue($backups->verify($safety['name']));
        $this->assertFalse($this->kit()->inMaintenance());
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.backup_restored']);

        // A modified backup fails verification and cannot be restored.
        file_put_contents($backups->dir($manifest['name']).'/files.zip', 'corrupted');
        $this->assertFalse($backups->verify($manifest['name']));
        $this->expectException(\RuntimeException::class);
        $backups->restore($manifest['name'], $user);
    }

    public function test_release_builder_produces_an_install_package_and_a_verifiable_update(): void
    {
        $source = $this->sandbox.'/source';
        foreach (['app', 'public/build', 'vendor', 'storage/app', 'node_modules/x', 'public/uploads', 'resources'] as $dir) {
            mkdir($source.'/'.$dir, 0755, true);
        }
        file_put_contents($source.'/VERSION', "1.2.0\n");
        file_put_contents($source.'/CHANGELOG.md', "# Log\n\n## [1.2.0] – 2026-11-01\n### Adăugat\n- Funcție nouă.\n\n## [1.1.0] – 2026-10-01\n- Veche.\n");
        file_put_contents($source.'/app/Thing.php', '<?php');
        file_put_contents($source.'/public/build/manifest.json', '{}');
        file_put_contents($source.'/vendor/autoload.php', '<?php');
        file_put_contents($source.'/storage/app/.gitignore', "*\n");
        file_put_contents($source.'/storage/app/installed.lock', 'x');
        file_put_contents($source.'/public/uploads/patient.jpg', 'private');
        file_put_contents($source.'/node_modules/x/a.js', 'x');
        file_put_contents($source.'/.env', 'APP_KEY=secret');

        $builder = new ReleaseBuilder($source);
        $files = $builder->collect();
        $this->assertArrayHasKey('app/Thing.php', $files);
        $this->assertArrayHasKey('storage/app/.gitignore', $files);
        foreach (['storage/app/installed.lock', 'public/uploads/patient.jpg', 'node_modules/x/a.js', '.env'] as $never) {
            $this->assertArrayNotHasKey($never, $files);
        }
        $this->assertSame('### Adăugat'."\n".'- Funcție nouă.', $builder->changelogSection());

        $install = $builder->buildInstall($this->sandbox.'/out');
        $zip = new ZipArchive;
        $zip->open($install);
        $this->assertNotFalse($zip->locateName('release.json'));
        $this->assertNotFalse($zip->locateName('app/Thing.php'));
        $zip->close();

        $update = $builder->buildUpdate($this->sandbox.'/out', base64_encode($this->secretKey), '1.0.0', ['ro' => 'x', 'hu' => 'y']);
        $package = new UpdatePackage($update);
        $this->assertTrue($package->inspect(), implode(' ', $package->errors));
        $this->assertArrayNotHasKey('storage/app/.gitignore', $package->manifest['files']);
        $this->assertArrayHasKey('release.json', $package->manifest['files']);
    }
}
