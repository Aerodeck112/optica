<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Ed25519 key pair for signing update packages (D-009). The secret key is written outside the
 * application and never shipped; the public key goes into resources/update-keys.txt.
 */
class UpdateKeygen extends Command
{
    protected $signature = 'optica:update-keygen {path : file for the secret key, outside the application}';

    protected $description = 'Creates the signing key pair for update packages';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $dir = realpath(dirname($path));
        if ($dir === false) {
            $this->error('The directory does not exist.');

            return self::FAILURE;
        }
        if (str_starts_with($dir.'/', rtrim(base_path(), '/').'/')) {
            $this->error('The secret key must not be stored inside the application.');

            return self::FAILURE;
        }
        if (file_exists($path)) {
            $this->error('The file already exists; an existing key is never overwritten.');

            return self::FAILURE;
        }
        $pair = sodium_crypto_sign_keypair();
        file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($pair))."\n");
        chmod($path, 0600);
        $this->info('Secret key: '.$path.' (keep it offline, never commit it).');
        $this->line('Public key for resources/update-keys.txt:');
        $this->line(base64_encode(sodium_crypto_sign_publickey($pair)));

        return self::SUCCESS;
    }
}
