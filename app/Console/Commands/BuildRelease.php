<?php

namespace App\Console\Commands;

use App\Domain\System\ReleaseBuilder;
use Illuminate\Console\Command;

/**
 * Packages a version for delivery (INS-01, UPD-01). Run on the build machine, never on the
 * customer's server: the source must have production dependencies (composer install --no-dev)
 * and compiled assets (npm run build).
 */
class BuildRelease extends Command
{
    protected $signature = 'optica:release
        {--source= : application directory to package (default: this one)}
        {--out=build : output directory}
        {--sign-key= : file with the Ed25519 secret key; without it only the install package is built}
        {--min-from= : oldest version the update applies to}
        {--changelog-hu= : file with the Hungarian changelog}
        {--allow-dev : package even if development dependencies are present}';

    protected $description = 'Builds the install ZIP and the signed update ZIP of the current version';

    public function handle(): int
    {
        $source = rtrim((string) ($this->option('source') ?: base_path()), '/');
        $builder = new ReleaseBuilder($source);
        if ($builder->hasDevPackages() && ! $this->option('allow-dev')) {
            $this->error('vendor/ contains development packages. Run "composer install --no-dev --optimize-autoloader" in the source first.');

            return self::FAILURE;
        }
        $files = $builder->collect();
        $out = (string) $this->option('out');
        $out = str_starts_with($out, '/') ? $out : getcwd().'/'.$out;
        $this->info(count($files).' files, version '.$builder->version());
        $this->line('Install package: '.$builder->buildInstall($out));

        if ($keyFile = $this->option('sign-key')) {
            if (! is_file($keyFile)) {
                $this->error('Signing key not found.');

                return self::FAILURE;
            }
            $changelogRo = $builder->changelogSection();
            $changelogHu = $this->option('changelog-hu') ? trim((string) file_get_contents($this->option('changelog-hu'))) : $changelogRo;
            $path = $builder->buildUpdate($out, (string) file_get_contents($keyFile), (string) ($this->option('min-from') ?: '0.0.0'), ['ro' => $changelogRo, 'hu' => $changelogHu]);
            $this->line('Update package: '.$path);
        }

        return self::SUCCESS;
    }
}
