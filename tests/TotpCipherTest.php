<?php

namespace App\Tests;

use App\Security\TotpCipher;
use PHPUnit\Framework\TestCase;

final class TotpCipherTest extends TestCase
{
    public function testWrongKeyCannotReadProtectedData(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hostdesk-key-');
        chmod($path, 0600);
        try {
            file_put_contents($path, sodium_crypto_secretbox_keygen());
            $cipher = new TotpCipher($path, '/');
            $secret = bin2hex(random_bytes(16));
            $encrypted = $cipher->encrypt($secret);
            self::assertTrue(hash_equals($secret, $cipher->decrypt($encrypted)));
            self::assertFalse(str_contains($encrypted, $secret));
            file_put_contents($path, sodium_crypto_secretbox_keygen());
            $this->expectException(\RuntimeException::class);
            $cipher->decrypt($encrypted);
        } finally { unlink($path); }
    }
}
