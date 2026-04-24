<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use EvoKore\Services\EvoService;
use EvoKore\Support\UnitContextResolver;
use PDO;
use RuntimeException;
use Throwable;

final class N8nTrialClassController extends N8nFlowController
{
    public function handle(): void
    {
        try {
            $context = $this->requireUnitContext();
            $payload = $this->getJsonBody();
            $normalized = $this->normalizePayload($payload);

            $db = $this->db();
            if ($db === null) {
                throw new RuntimeException('Database unavailable.');
            }

            $this->ensureTables($db);
            $resolver = new UnitContextResolver($db, (array) ($this->app['env'] ?? []));
            $credential = $resolver->resolveEvoCredentialByUnit((int) $context['unit_id']);

            $evoService = new EvoService([
                'base_url' => (string) ($this->app['env']['EVO_BASE_URL'] ?? ''),
                'dns' => $credential['dns'],
                'token' => $credential['token'],
                'api_key' => $credential['token'],
                'auth_mode' => (string) ($this->app['env']['EVO_AUTH_MODE'] ?? 'basic'),
                'dns_header_name' => (string) ($this->app['env']['EVO_DNS_HEADER_NAME'] ?? 'DNS'),
                'timeout_seconds' => (int) ($this->app['env']['EVO_TIMEOUT_SECONDS'] ?? 10),
                'max_retries' => (int) ($this->app['env']['EVO_MAX_RETRIES'] ?? 2),
                'log_file' => (string) ($this->app['paths']['base'] ?? __DIR__ . '/../../') . '/logs/evo-trials.log',
            ]);

            $evoResponse = $evoService->createTrialClass($normalized['evo_payload']);
            $trialId = $this->persistTrial($db, $context, $normalized, $evoResponse);

            $this->json(201, [
                'data' => [
                    'trial_id' => $trialId,
                    'unit_id' => (int) $context['unit_id'],
                    'unit_name' => (string) $context['unit_name'],
                    'cpf' => $normalized['cpf'],
                    'customer_name' => $normalized['customer_name'],
                    'preferred_date' => $normalized['preferred_date'],
                    'preferred_time' => $normalized['preferred_time'],
                    'evo_response' => $evoResponse,
                ],
            ]);
        } catch (RuntimeException $e) {
            $this->json(400, ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->json(500, ['error' => 'Internal Server Error', 'detail' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $cpf = preg_replace('/\D+/', '', (string) ($payload['cpf'] ?? '')) ?? '';
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $preferredDate = trim((string) ($payload['preferred_date'] ?? ''));
        $preferredTime = trim((string) ($payload['preferred_time'] ?? ''));

        if (strlen($cpf) !== 11) {
            throw new RuntimeException('CPF inválido (11 dígitos).');
        }
        if ($customerName === '') {
            throw new RuntimeException('customer_name é obrigatório.');
        }
        if ($preferredDate === '') {
            throw new RuntimeException('preferred_date é obrigatório (YYYY-MM-DD).');
        }

        return [
            'cpf' => $cpf,
            'customer_name' => $customerName,
            'phone' => $phone,
            'email' => $email,
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
            'status' => strtoupper(trim((string) ($payload['status'] ?? 'SCHEDULED'))),
            'evo_payload' => [
                'cpf' => $cpf,
                'customer_name' => $customerName,
                'phone' => $phone,
                'email' => $email,
                'preferred_date' => $preferredDate,
                'preferred_time' => $preferredTime,
                'meta' => $payload['meta'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $evoResponse
     */
    private function persistTrial(PDO $db, array $context, array $normalized, array $evoResponse): int
    {
        $stmt = $db->prepare(
            'INSERT INTO trial_classes
                (client_id, unit_id, cpf, customer_name, phone, email, preferred_date, preferred_time, status, evo_payload_json, evo_response_json, created_at, updated_at)
             VALUES
                (:client_id, :unit_id, :cpf, :customer_name, :phone, :email, :preferred_date, :preferred_time, :status, :evo_payload_json, :evo_response_json, NOW(), NOW())'
        );

        $stmt->execute([
            ':client_id' => (int) $context['client_id'],
            ':unit_id' => (int) $context['unit_id'],
            ':cpf' => (string) $normalized['cpf'],
            ':customer_name' => (string) $normalized['customer_name'],
            ':phone' => (string) $normalized['phone'],
            ':email' => (string) $normalized['email'],
            ':preferred_date' => (string) $normalized['preferred_date'],
            ':preferred_time' => (string) $normalized['preferred_time'],
            ':status' => (string) $normalized['status'],
            ':evo_payload_json' => json_encode($normalized['evo_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':evo_response_json' => json_encode($evoResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $db->lastInsertId();
    }

    private function ensureTables(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS trial_classes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                unit_id INT NOT NULL,
                cpf VARCHAR(14) NOT NULL,
                customer_name VARCHAR(255) NOT NULL,
                phone VARCHAR(40) NULL,
                email VARCHAR(255) NULL,
                preferred_date DATE NOT NULL,
                preferred_time VARCHAR(20) NULL,
                trial_date DATE NULL,
                trial_time VARCHAR(20) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "SCHEDULED",
                status_note VARCHAR(255) NULL,
                evo_payload_json JSON NULL,
                evo_response_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_trial_dates (created_at),
                INDEX idx_trial_client (client_id),
                INDEX idx_trial_unit (unit_id),
                INDEX idx_trial_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $db->exec('ALTER TABLE trial_classes ADD COLUMN IF NOT EXISTS trial_date DATE NULL');
        $db->exec('ALTER TABLE trial_classes ADD COLUMN IF NOT EXISTS trial_time VARCHAR(20) NULL');
        $db->exec('ALTER TABLE trial_classes ADD COLUMN IF NOT EXISTS status_note VARCHAR(255) NULL');
    }
}
