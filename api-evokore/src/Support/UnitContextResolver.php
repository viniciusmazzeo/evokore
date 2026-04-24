<?php
declare(strict_types=1);

namespace EvoKore\Support;

use EvoKore\Security\TokenCipher;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class UnitContextResolver
{
    private PDO $db;
    private TokenCipher $cipher;

    /**
     * @param array<string,mixed> $env
     */
    public function __construct(PDO $db, array $env = [])
    {
        $this->db = $db;
        $this->cipher = new TokenCipher($env);
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveByToken(string $token): array
    {
        $cleanToken = trim($token);
        if ($cleanToken === '') {
            throw new RuntimeException('Unit token is required.');
        }

        try {
            $tokenHash = hash('sha256', $cleanToken);
            $stmt = $this->db->prepare(
                'SELECT t.id AS token_id,
                        t.unit_id,
                        t.token_hash,
                        t.expires_at,
                        t.is_active,
                        u.client_id,
                        u.unit_code,
                        u.unit_name
                 FROM unit_api_tokens t
                 INNER JOIN units u ON u.id = t.unit_id
                 WHERE t.token_hash = :token_hash
                 LIMIT 1'
            );
            $stmt->execute([':token_hash' => $tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new RuntimeException('Unit token table unavailable: ' . $e->getMessage());
        }

        if (!is_array($row) || $row === []) {
            throw new RuntimeException('Invalid or expired unit token.');
        }

        if ((int) ($row['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Inactive unit token.');
        }

        $expiresAt = $row['expires_at'] ?? null;
        if (is_string($expiresAt) && trim($expiresAt) !== '') {
            $expiry = new DateTimeImmutable($expiresAt);
            if ($expiry < new DateTimeImmutable('now')) {
                throw new RuntimeException('Expired unit token.');
            }
        }

        $this->touchTokenLastUsed((int) $row['token_id']);

        return [
            'unit_id' => (int) $row['unit_id'],
            'client_id' => (int) $row['client_id'],
            'unit_code' => (string) ($row['unit_code'] ?? ''),
            'unit_name' => (string) ($row['unit_name'] ?? ''),
            'token_id' => (int) $row['token_id'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function resolveEvoCredentialByUnit(int $unitId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT evo_dns, token_encrypted
                 FROM unit_evo_credentials
                 WHERE unit_id = :unit_id AND is_active = 1
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute([':unit_id' => $unitId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new RuntimeException('Unit EVO credentials unavailable: ' . $e->getMessage());
        }

        if (!is_array($row) || $row === []) {
            throw new RuntimeException('No active EVO credential found for unit.');
        }

        $dns = trim((string) ($row['evo_dns'] ?? ''));
        $tokenStored = trim((string) ($row['token_encrypted'] ?? ''));
        $token = $this->cipher->decrypt($tokenStored);
        if ($dns === '' || $token === '') {
            throw new RuntimeException('Invalid EVO credential for unit.');
        }

        return ['dns' => $dns, 'token' => $token];
    }

    private function touchTokenLastUsed(int $tokenId): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE unit_api_tokens SET last_used_at = NOW() WHERE id = :id');
            $stmt->execute([':id' => $tokenId]);
        } catch (Throwable) {
            // Falha de update de telemetria não pode quebrar o fluxo.
        }
    }
}
