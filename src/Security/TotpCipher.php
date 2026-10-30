<?php

namespace App\Security;

final class TotpCipher
{
    public function __construct(private string $keyFile, private string $projectDir) {}

    private function key(): string
    {
        $path = str_starts_with($this->keyFile, '/') ? $this->keyFile : $this->projectDir.'/'.$this->keyFile;
        if (!is_file($path) || !is_readable($path) || (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('TOTP key must be a readable protected file (mode 0600).');
        }
        $key = file_get_contents($path);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid TOTP key file.');
        }
        return $key;
    }
    public function encrypt(#[\SensitiveParameter] string $secret): string
    {
        $key = $this->key();
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $key));
        } finally { sodium_memzero($key); }
    }
    public function decrypt(#[\SensitiveParameter] string $ciphertext): string
    {
        $key = $this->key();
        try {
            $raw = str_starts_with($ciphertext, 'v1:') ? base64_decode(substr($ciphertext, 3), true) : false;
            if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                throw new \RuntimeException('Protected TOTP data could not be read.');
            }
            $secret = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
            if ($secret === false) { throw new \RuntimeException('Protected TOTP data could not be read.'); }
            return $secret;
        } finally { sodium_memzero($key); }
    }
}
