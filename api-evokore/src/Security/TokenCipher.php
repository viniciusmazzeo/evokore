<?php
declare(strict_types=1);

namespace EvoKore\Security;

use RuntimeException;

final class TokenCipher
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $key;

    /**
     * @param array<string,mixed> $env
     */
    public function __construct(array $env)
    {
        $raw = trim((string) ($env['TOKENS_ENC_KEY'] ?? ''));
        if ($raw === '') {
            $this->key = '';
            return;
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if (!is_string($decoded) || $decoded === '') {
                throw new RuntimeException('TOKENS_ENC_KEY invalida (base64).');
            }
            $raw = $decoded;
        }

        $this->key = hash('sha256', $raw, true);
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if ($this->key === '') {
            throw new RuntimeException('TOKENS_ENC_KEY nao configurada.');
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt(
            $plain,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (!is_string($cipher) || $cipher === '' || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Falha ao criptografar token.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (!str_starts_with($stored, self::PREFIX)) {
            // Compatibilidade legado: valor em texto puro.
            return $stored;
        }

        if ($this->key === '') {
            throw new RuntimeException('TOKENS_ENC_KEY nao configurada para descriptografar token.');
        }

        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) <= (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new RuntimeException('Token cifrado invalido.');
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);

        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException('Falha ao descriptografar token.');
        }

        return $plain;
    }
}
