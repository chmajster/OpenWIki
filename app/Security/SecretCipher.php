<?php

declare(strict_types=1);

namespace OpenWiki\Security;

final class SecretCipher
{
    private readonly string $key;

    public function __construct(string $applicationKey)
    {
        if (strlen($applicationKey) < 32) {
            throw new \RuntimeException('Application key is not configured correctly.');
        }

        $this->key = hash('sha256', $applicationKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new \InvalidArgumentException('Secret cannot be empty.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'openwiki:secret:v1',
            16
        );

        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('Unable to encrypt secret.');
        }

        return base64_encode(json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $encoded): string
    {
        $json = base64_decode($encoded, true);
        if ($json === false) {
            throw new \RuntimeException('Encrypted secret is invalid.');
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1) {
            throw new \RuntimeException('Encrypted secret format is unsupported.');
        }

        $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
        $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($payload['data'] ?? ''), true);
        if ($iv === false || $tag === false || $ciphertext === false) {
            throw new \RuntimeException('Encrypted secret payload is invalid.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'openwiki:secret:v1'
        );

        if (!is_string($plaintext)) {
            throw new \RuntimeException('Unable to decrypt secret.');
        }

        return $plaintext;
    }
}
