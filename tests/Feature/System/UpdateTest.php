<?php

namespace Tests\Feature\System;

use App\Domain\System\UpdatePackage;
use App\Domain\System\UpdateRunner;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SystemSandbox;
use Tests\TestCase;

/** T10 and T11: signed packages, valid update, rejected packages, failed and interrupted updates. */
class UpdateTest extends TestCase
{
    use SystemSandbox;

    private const MIGRATION = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('update_demo_items', function (Blueprint $t) { $t->id(); $t->string('name'); }); }
    public function down(): void { Schema::dropIfExists('update_demo_items'); }
};
PHP;

    private const BROKEN_MIGRATION = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('update_broken', fn (Blueprint $t) => $t->id()); throw new RuntimeException('broken migration'); }
};
PHP;

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

    private function runner(): UpdateRunner
    {
        return new UpdateRunner($this->kit());
    }

    private function runToEnd(UpdateRunner $runner): array
    {
        for ($i = 0; $i < 50; $i++) {
            $journal = $runner->advance();
            if ($journal['status'] !== 'running') {
                return $journal;
            }
        }
        $this->fail('The update did not finish');
    }

    private function goodPackage(): string
    {
        return $this->package('1.1.0', [
            'app/Kept.php' => (string) file_get_contents($this->sandbox.'/app/Kept.php'),
            'app/Changed.php' => "<?php // version 1.1.0\n",
            'app/Added.php' => "<?php // new in 1.1.0\n",
            'database/migrations/2099_01_01_000000_create_update_demo_items.php' => self::MIGRATION,
        ]);
    }

    private function patient(): Patient
    {
        return Patient::create(['code' => 'P900100', 'first_name' => 'Ana', 'last_name' => 'Fictiv', 'locale' => 'ro']);
    }

    public function test_valid_update_applies_files_and_migrations_and_keeps_data(): void
    {
        $patient = $this->patient();
        $runner = $this->runner();
        $prepared = $runner->prepare($this->goodPackage(), 'optica-1.1.0-update.zip', null);
        $this->assertTrue($prepared['ok'], implode(' ', $prepared['errors']));
        $this->assertSame(['app/Removed.php'], $prepared['journal']['delete']);
        // Unchanged files are not rewritten.
        $this->assertNotContains('app/Kept.php', $prepared['journal']['apply']);

        $runner->start(null);
        $journal = $this->runToEnd($runner);

        $this->assertSame('done', $journal['status'], json_encode($journal['log']));
        $this->assertSame("1.1.0\n", file_get_contents($this->sandbox.'/VERSION'));
        $this->assertStringContainsString('1.1.0', file_get_contents($this->sandbox.'/app/Changed.php'));
        $this->assertFileExists($this->sandbox.'/app/Added.php');
        $this->assertFileDoesNotExist($this->sandbox.'/app/Removed.php');
        $this->assertTrue(Schema::hasTable('update_demo_items'));
        $this->assertFalse($this->kit()->inMaintenance());
        $this->assertNotNull(Patient::find($patient->id));
        $this->assertSame('done', DB::table('update_runs')->value('status'));
        // A verified backup was taken before any change.
        $this->assertNotEmpty(glob($this->sandbox.'/storage/app/private/backups/*-pre_update-*/manifest.json'));
    }

    public function test_packages_that_are_not_authentic_or_not_safe_are_rejected(): void
    {
        $runner = $this->runner();
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $cases = [
            'untrusted key' => $this->package('1.1.0', ['app/X.php' => 'x'], [], $other),
            'tampered file' => (function () {
                $path = $this->package('1.1.0', ['app/X.php' => 'original']);
                $zip = new \ZipArchive;
                $zip->open($path);
                $zip->addFromString('files/app/X.php', 'tampered');
                $zip->close();

                return $path;
            })(),
            'zip slip' => $this->package('1.1.0', ['app/X.php' => 'x'], [], null, ['files/../../evil.php' => 'x']),
            'protected path' => $this->package('1.1.0', ['.env' => 'APP_KEY=stolen']),
            'unlisted file' => $this->package('1.1.0', ['app/X.php' => 'x'], [], null, ['files/app/Hidden.php' => 'x']),
            'not newer' => $this->package('1.0.0', ['app/X.php' => 'x']),
            'source too old' => $this->package('2.0.0', ['app/X.php' => 'x'], ['min_from_version' => '1.5.0']),
        ];
        foreach ($cases as $name => $path) {
            $result = $runner->prepare($path, $name.'.zip', null);
            $this->assertFalse($result['ok'], $name);
            $this->assertNotEmpty($result['errors'], $name);
        }
        $this->assertSame("1.0.0\n", file_get_contents($this->sandbox.'/VERSION'));
        $this->assertFileDoesNotExist($this->sandbox.'/evil.php');
        $this->assertSame(7, DB::table('update_runs')->where('status', 'rejected')->count());
    }

    public function test_signature_check_alone(): void
    {
        $path = $this->package('1.1.0', ['app/X.php' => 'x']);
        $this->assertTrue((new UpdatePackage($path))->inspect());
        config(['optica.updates.public_keys' => []]);
        $package = new UpdatePackage($path);
        $this->assertFalse($package->inspect());
        $this->assertSame([__('system.updates.errors.signature')], $package->errors);
    }

    public function test_failed_migration_rolls_everything_back_automatically(): void
    {
        $patient = $this->patient();
        $runner = $this->runner();
        $path = $this->package('1.1.0', [
            'app/Changed.php' => "<?php // version 1.1.0\n",
            'app/Added.php' => "<?php // new\n",
            'database/migrations/2099_01_01_000001_broken.php' => self::BROKEN_MIGRATION,
        ]);
        $this->assertTrue($runner->prepare($path, 'broken.zip', null)['ok']);
        $runner->start(null);
        $journal = $this->runToEnd($runner);

        $this->assertSame('rolled_back', $journal['status']);
        $this->assertStringContainsString('broken migration', $journal['error']);
        $this->assertSame("1.0.0\n", file_get_contents($this->sandbox.'/VERSION'));
        $this->assertStringContainsString('1.0.0', file_get_contents($this->sandbox.'/app/Changed.php'));
        $this->assertFileDoesNotExist($this->sandbox.'/app/Added.php');
        $this->assertFileExists($this->sandbox.'/app/Removed.php');
        $this->assertFalse(Schema::hasTable('update_broken'));
        $this->assertNotNull(Patient::find($patient->id));
        $this->assertFalse($this->kit()->inMaintenance());
        $this->assertSame('rolled_back', DB::table('update_runs')->value('status'));
    }

    public function test_interrupted_update_is_recovered_without_the_application(): void
    {
        config(['optica.updates.apply_batch' => 1]);
        $patient = $this->patient();
        $runner = $this->runner();
        $this->assertTrue($runner->prepare($this->goodPackage(), 'good.zip', null)['ok']);
        $runner->start(null);
        // backup, maintenance, then one file of several: the process "dies" here.
        $runner->advance();
        $runner->advance();
        $journal = $runner->advance();
        $this->assertSame('apply', $journal['step']);
        $this->assertTrue($this->kit()->inMaintenance());
        $this->assertTrue($this->kit()->interrupted());

        // What public/recovery.php does: framework-free, with its own database connection.
        $journal = $this->kit()->rollback();

        $this->assertSame('rolled_back', $journal['status']);
        $this->assertSame("1.0.0\n", file_get_contents($this->sandbox.'/VERSION'));
        $this->assertStringContainsString('1.0.0', file_get_contents($this->sandbox.'/app/Changed.php'));
        $this->assertFileDoesNotExist($this->sandbox.'/app/Added.php');
        $this->assertFalse($this->kit()->inMaintenance());
        DB::purge('mysql');
        $this->assertNotNull(Patient::find($patient->id));
    }

    public function test_interrupted_update_can_also_be_resumed(): void
    {
        config(['optica.updates.apply_batch' => 1]);
        $runner = $this->runner();
        $runner->prepare($this->goodPackage(), 'good.zip', null);
        $runner->start(null);
        $runner->advance();
        $runner->advance();
        $runner->advance();
        // A new request (another runner instance) continues from the journal.
        $journal = $this->runToEnd(new UpdateRunner($this->kit()));
        $this->assertSame('done', $journal['status']);
        $this->assertSame("1.1.0\n", file_get_contents($this->sandbox.'/VERSION'));
        $this->assertTrue(Schema::hasTable('update_demo_items'));
    }
}
