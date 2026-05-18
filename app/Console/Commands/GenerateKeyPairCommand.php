<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateKeyPairCommand extends Command
{
    protected $signature = 'licensing:generate-keys {--force : Overwrite existing keys}';
    protected $description = 'Generate RSA-4096 key pair for license signing';

    public function handle(): int
    {
        $privateKeyPath = config('licensing.keys.private');
        $publicKeyPath = config('licensing.keys.public');

        $dir = dirname($privateKeyPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($privateKeyPath) && !$this->option('force')) {
            $this->error('Keys already exist. Use --force to overwrite.');
            return self::FAILURE;
        }

        $this->info('Generating RSA-4096 key pair...');

        $config = [
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = openssl_pkey_new($config);

        if (!$resource) {
            $this->error('Failed to generate key pair: ' . openssl_error_string());
            return self::FAILURE;
        }

        openssl_pkey_export($resource, $privateKey);
        $publicKey = openssl_pkey_get_details($resource)['key'];

        file_put_contents($privateKeyPath, $privateKey);
        file_put_contents($publicKeyPath, $publicKey);

        chmod($privateKeyPath, 0600);
        chmod($publicKeyPath, 0644);

        $this->info('RSA-4096 key pair generated successfully.');
        $this->info("Private key: {$privateKeyPath}");
        $this->info("Public key: {$publicKeyPath}");

        return self::SUCCESS;
    }
}
