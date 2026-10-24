<?php

/**
 * Construiește un pachet de actualizare pentru tabul „Sistem → Actualizări” din panou.
 *
 *   php tools/make-update.php <commit-de-la> <versiune> "descriere" [--output=cale.zip]
 *
 * Include fișierele modificate între <commit-de-la> și HEAD (aplicația → app/, public/ → public/), resursele
 * compilate (public/build) dacă s-au schimbat stilurile/scripturile, fișierele șterse și noul VERSION.
 * Dacă s-au schimbat dependențele (composer.lock), pachetul include și folderul vendor (fără pachete de dezvoltare).
 */

declare(strict_types=1);

[$script, $from, $version, $description] = $argv + [null, null, null, ''];
$options = getopt('', ['output::']);
if (! $from || ! preg_match('/^\d+\.\d+\.\d+$/', (string) $version)) {
    fwrite(STDERR, "Utilizare: php tools/make-update.php <commit> <versiune x.y.z> \"descriere\" [--output=fisier.zip]\n");
    exit(1);
}

$root = dirname(__DIR__);
chdir($root);
$run = function (string $command) {
    exec($command.' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Eroare la: {$command}\n".implode("\n", $output)."\n");
        exit(1);
    }

    return $output;
};

$changes = $run('git diff --name-status --no-renames '.escapeshellarg($from).' HEAD');
$skip = fn (string $path) => preg_match('#^(tests/|\.github/|\.claude/|node_modules/|storage/|bootstrap/cache/|\.env|public/index\.php$|public/instalare\.php$|public/\.htaccess$|public/storage)#', $path);
$files = [];
$deleted = [];
$rebuildAssets = false;
$vendor = false;
foreach ($changes as $line) {
    [$status, $path] = preg_split('/\s+/', $line, 2);
    if (str_starts_with($path, 'resources/css/') || str_starts_with($path, 'resources/js/') || str_starts_with($path, 'resources/fonts/') || $path === 'vite.config.js') {
        $rebuildAssets = true;
    }
    if ($path === 'composer.lock') {
        $vendor = true;
    }
    if ($skip($path)) {
        continue;
    }
    $entry = str_starts_with($path, 'public/') ? $path : 'app/'.$path;
    if ($status === 'D') {
        $deleted[] = $entry;
    } else {
        $files[$entry] = $root.'/'.$path;
    }
}

if ($rebuildAssets) {
    $run('npm run build');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/build', FilesystemIterator::SKIP_DOTS)) as $file) {
        $files['public/build/'.substr($file->getPathname(), strlen($root.'/public/build/'))] = $file->getPathname();
    }
}

$vendorDir = null;
if ($vendor) {
    $vendorDir = sys_get_temp_dir().'/aw-vendor-'.getmypid();
    $run('mkdir -p '.escapeshellarg($vendorDir).' && cp composer.json composer.lock '.escapeshellarg($vendorDir));
    $run('cd '.escapeshellarg($vendorDir).' && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress');
    $run('find '.escapeshellarg($vendorDir.'/vendor').' -name .git -type d -prune -exec rm -rf {} +');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendorDir.'/vendor', FilesystemIterator::SKIP_DOTS)) as $file) {
        $files['app/vendor/'.substr($file->getPathname(), strlen($vendorDir.'/vendor/'))] = $file->getPathname();
    }
    fwrite(STDOUT, "Dependențele s-au schimbat: pachetul include folderul vendor complet.\n");
}

$output = $options['output'] ?? $root.'/actualizare-'.$version.'.zip';
@unlink($output);
$zip = new ZipArchive;
$zip->open($output, ZipArchive::CREATE);
$zip->addFromString('aw-update.json', json_encode(['format' => 1, 'version' => $version, 'description' => $description, 'delete' => $deleted, 'migrate' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
foreach ($files as $entry => $source) {
    $zip->addFile($source, $entry);
}
$zip->addFromString('app/VERSION', $version."\n");
$zip->close();

echo "Pachet: {$output}\n".count($files).' fișiere, '.count($deleted).' șterse'.($rebuildAssets ? ', resurse recompilate' : '')."\n";
