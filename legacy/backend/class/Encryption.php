<?php

class Encryption
{
    private string $encryptionKey;
    private string $cipher = 'AES-256-CBC';

    public function __construct(string $key)
    {
        if (empty($key)) {
            throw new InvalidArgumentException('Encryption key must not be empty.');
        }

        // Normalize key to 32 bytes for AES-256
        $this->encryptionKey = hash('sha256', $key, true);
    }
    public function encrypt(string $plaintext): string {
        $ivLength = openssl_cipher_iv_length($this->cipher);
        try { $iv = random_bytes($ivLength);
        } catch (\Random\RandomException $e) { }
        $ciphertext = openssl_encrypt($plaintext, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        // HMAC for tamper detection
        $hmac = hash_hmac('sha256', $ciphertext . $iv, $this->encryptionKey, true);
        // Return base64-encoded string of IV + HMAC + ciphertext
        return base64_encode($iv . $hmac . $ciphertext);
    }
    public function decrypt(string $encoded): string {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 48) {
            throw new RuntimeException('Invalid encrypted data.');
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($data, 0, $ivLength);
        $hmac = substr($data, $ivLength, 32);
        $ciphertext = substr($data, $ivLength + 32);

        $calculatedHmac = hash_hmac('sha256', $ciphertext . $iv, $this->encryptionKey, true);
        if (!hash_equals($hmac, $calculatedHmac)) {
            throw new RuntimeException('Data integrity check failed.');
        }

        $plaintext = openssl_decrypt($ciphertext, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }
}
