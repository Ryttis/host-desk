#!/usr/bin/env php
<?php

declare(strict_types=1);

// Standalone bootstrap: no Composer dependencies, no secret output, no overwrites.
$root = dirname(__DIR__);
umask(0077);
$directory = $root.'/var/keys';
if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new RuntimeException('Cannot create protected key directory.'); }
foreach (['totp.key', 'test-totp.key'] as $name) {
    $path = $directory.'/'.$name;
    if (!file_exists($path)) {
        $file = fopen($path, 'x');
        if ($file === false) { throw new RuntimeException('Cannot create key file.'); }
        fwrite($file, sodium_crypto_secretbox_keygen());
        fclose($file);
    }
    if (filesize($path) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || (fileperms($path) & 0077) !== 0) { throw new RuntimeException('Existing key is invalid or not protected.'); }
}
$dbPath = $directory.'/db.password';
if (!file_exists($dbPath)) { file_put_contents($dbPath, bin2hex(random_bytes(24)), LOCK_EX); }
$password = trim(file_get_contents($dbPath));
foreach (['.env.local' => 'hostdesk', '.env.test.local' => 'hostdesk_test'] as $file => $database) {
    $path = $root.'/'.$file;
    if (!file_exists($path)) {
        $handle = fopen($path, 'x');
        if (!$handle) { throw new RuntimeException('Cannot create local environment file.'); }
        fwrite($handle, 'APP_SECRET='.bin2hex(random_bytes(32))."\nDATABASE_URL=\"postgresql://hostdesk:".$password.'@127.0.0.1:55432/'.$database."?serverVersion=16&charset=utf8\"\n");
        fclose($handle);
    }
}
fwrite(STDOUT, "Local environment files and protected keys are ready. Existing files were preserved.\n");
