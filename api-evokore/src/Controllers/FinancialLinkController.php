<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use EvoKore\Security\TokenCipher;
use EvoKore\Services\FinancialStatusService;
use PDO;

final class FinancialLinkController
{
    /**
     * @var array<string,mixed>
     */
    private array $app;
    /**
     * @var array<string,mixed>|null
     */
    private ?array $jsonBody = null;

    /**
     * @param array<string,mixed> $app
     */
    public function __construct(array $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $startedAt = microtime(true);
        $httpStatus = 200;
        $success = 0;
        $errorCode = null;
        $errorMessage = null;
        $unitIdForLog = null;
        $clientIdForLog = null;
        $requestId = $this->extractRequestId();

        $provided = $this->extractAccessToken();
        if ($provided === '') {
            $httpStatus = 401;
            $errorCode = 'UNAUTHORIZED';
            $errorMessage = 'Unauthorized';
            $this->json($httpStatus, ['error' => 'Unauthorized']);
            $this->writeIntegrationLog(
                $startedAt,
                '/financial/link',
                $httpStatus,
                $success,
                $requestId,
                $unitIdForLog,
                $clientIdForLog,
                $errorCode,
                $errorMessage
            );
            return;
        }

        $unitContext = $this->resolveUnitContextByAccessToken($provided);
        if ($unitContext === null) {
            $httpStatus = 401;
            $errorCode = 'UNIT_TOKEN_INVALID';
            $errorMessage = 'Invalid or expired unit token';
            $this->json($httpStatus, ['error' => 'Invalid or expired unit token']);
            $this->writeIntegrationLog(
                $startedAt,
                '/financial/link',
                $httpStatus,
                $success,
                $requestId,
                $unitIdForLog,
                $clientIdForLog,
                $errorCode,
                $errorMessage
            );
            return;
        }
        $unitIdForLog = (int) ($unitContext['unit_id'] ?? 0);
        $clientIdForLog = (int) ($unitContext['client_id'] ?? 0);

        $memberId = $this->extractMemberId();
        $cpf = $this->extractCpf();
        if ($memberId === null && $cpf === '') {
            $httpStatus = 400;
            $errorCode = 'VALIDATION_ERROR';
            $errorMessage = 'memberId or CPF is required';
            $this->json($httpStatus, ['error' => 'memberId or CPF is required']);
            $this->writeIntegrationLog(
                $startedAt,
                '/financial/link',
                $httpStatus,
                $success,
                $requestId,
                $unitIdForLog,
                $clientIdForLog,
                $errorCode,
                $errorMessage
            );
            return;
        }

        try {
            $service = new FinancialStatusService([
                'base_url' => (string) ($this->app['env']['EVO_BASE_URL'] ?? ''),
                'dns' => (string) ($unitContext['evo_dns'] ?? ''),
                'dns_header_name' => (string) ($this->app['env']['EVO_DNS_HEADER_NAME'] ?? 'DNS'),
                'token' => (string) ($unitContext['token_encrypted'] ?? ''),
                'auth_mode' => (string) ($this->app['env']['EVO_AUTH_MODE'] ?? 'basic'),
                'timeout_seconds' => (int) ($this->app['env']['EVO_TIMEOUT_SECONDS'] ?? 10),
                'max_retries' => (int) ($this->app['env']['EVO_MAX_RETRIES'] ?? 2),
                'log_file' => (string) ($this->app['paths']['base'] ?? __DIR__ . '/../../') . '/logs/financial.log',
            ]);

            if ($memberId !== null) {
                $result = $service->getPaymentLinkByMemberId($memberId, $cpf !== '' ? $cpf : null);
            } else {
                $result = $service->getPaymentLinkByCpf($cpf);
            }
            $result['unit_id'] = (int) ($unitContext['unit_id'] ?? 0);
            $result['unit_code'] = (string) ($unitContext['unit_code'] ?? '');
            $this->persistLinkDebtorEvent($unitContext, $result, $cpf);
            $success = 1;
            $this->json(200, $result);
        } catch (\Throwable $e) {
            $httpStatus = 502;
            $errorCode = 'FINANCIAL_LINK_ERROR';
            $errorMessage = $e->getMessage();
            $this->json($httpStatus, [
                'error' => 'EVO integration error',
                'detail' => $errorMessage,
            ]);
        } finally {
            $this->writeIntegrationLog(
                $startedAt,
                '/financial/link',
                $httpStatus,
                $success,
                $requestId,
                $unitIdForLog,
                $clientIdForLog,
                $errorCode,
                $errorMessage
            );
        }
    }

    private function extractMemberId(): ?int
    {
        $fromQuery = (string) ($_GET['memberId'] ?? $_GET['idMember'] ?? '');
        if ($fromQuery !== '') {
            $digits = preg_replace('/\D+/', '', $fromQuery) ?? '';
            if ($digits !== '' && (int) $digits > 0) {
                return (int) $digits;
            }
        }

        $decoded = $this->getJsonBody();
        if ($decoded === []) {
            return null;
        }

        $value = (string) ($decoded['memberId'] ?? $decoded['idMember'] ?? '');
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '' || (int) $digits <= 0) {
            return null;
        }

        return (int) $digits;
    }

    private function extractCpf(): string
    {
        $queryCpf = (string) ($_GET['cpf'] ?? '');
        if ($queryCpf !== '') {
            return $queryCpf;
        }

        $decoded = $this->getJsonBody();
        if ($decoded === []) {
            return '';
        }

        return (string) ($decoded['cpf'] ?? '');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveUnitContextByAccessToken(string $token): ?array
    {
        $db = $this->app['db'] ?? null;
        if (!$db instanceof PDO) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $sql = '
            SELECT
              t.id AS token_id,
              u.client_id,
              uc.unit_id,
              u.unit_code,
              uc.evo_dns,
              uc.token_encrypted
            FROM unit_api_tokens t
            INNER JOIN units u ON u.id = t.unit_id
            INNER JOIN unit_evo_credentials uc ON uc.unit_id = t.unit_id
            WHERE t.token_hash = :token_hash
              AND t.is_active = 1
              AND (t.expires_at IS NULL OR t.expires_at >= NOW())
              AND uc.is_active = 1
        ';

        $sql .= ' ORDER BY uc.updated_at DESC, t.updated_at DESC LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $update = $db->prepare('UPDATE unit_api_tokens SET last_used_at = NOW() WHERE id = :id');
        $update->execute([':id' => (int) ($row['token_id'] ?? 0)]);

        try {
            $cipher = new TokenCipher((array) ($this->app['env'] ?? []));
            $row['token_encrypted'] = $cipher->decrypt((string) ($row['token_encrypted'] ?? ''));
        } catch (\Throwable) {
            return null;
        }

        return $row;
    }

    private function extractRequestId(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'x-request-id' && is_scalar($value)) {
                    $id = trim((string) $value);
                    if ($id !== '') {
                        return substr($id, 0, 100);
                    }
                }
            }
        }

        $fallback = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        if (trim($fallback) !== '') {
            return substr(trim($fallback), 0, 100);
        }

        return 'req_fin_' . bin2hex(random_bytes(6));
    }

    private function writeIntegrationLog(
        float $startedAt,
        string $endpoint,
        int $httpStatus,
        int $success,
        string $requestId,
        ?int $unitId,
        ?int $clientId,
        ?string $errorCode,
        ?string $errorMessage
    ): void {
        $db = $this->app['db'] ?? null;
        if (!$db instanceof PDO) {
            return;
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        try {
            $stmt = $db->prepare(
                'INSERT INTO integration_logs (
                    client_id, unit_id, provider, endpoint, method, http_status,
                    latency_ms, request_id, success, error_code, error_message, meta_json
                ) VALUES (
                    :client_id, :unit_id, :provider, :endpoint, :method, :http_status,
                    :latency_ms, :request_id, :success, :error_code, :error_message, :meta_json
                )'
            );
            $stmt->execute([
                ':client_id' => $clientId && $clientId > 0 ? $clientId : null,
                ':unit_id' => $unitId && $unitId > 0 ? $unitId : null,
                ':provider' => 'EVO',
                ':endpoint' => $endpoint,
                ':method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                ':http_status' => $httpStatus,
                ':latency_ms' => $latencyMs,
                ':request_id' => $requestId,
                ':success' => $success,
                ':error_code' => $errorCode,
                ':error_message' => $errorMessage,
                ':meta_json' => json_encode(['source' => 'financial_api'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {
            // Nao derrubar fluxo por erro de log.
        }
    }

    /**
     * @param array<string,mixed> $unitContext
     * @param array<string,mixed> $result
     */
    private function persistLinkDebtorEvent(array $unitContext, array $result, string $fallbackCpf): void
    {
        $db = $this->app['db'] ?? null;
        if (!$db instanceof PDO) {
            return;
        }

        $checkoutLink = (string) ($result['checkoutLinkFullDebt'] ?? '');
        if (trim($checkoutLink) === '') {
            return;
        }

        $cpfRaw = (string) ($result['cpf'] ?? $fallbackCpf);
        $cpfDigits = preg_replace('/\D+/', '', $cpfRaw) ?? '';
        if (strlen($cpfDigits) !== 11) {
            return;
        }

        $cpfHash = hash('sha256', $cpfDigits);
        $cpfMask = substr($cpfDigits, 0, 3) . '.***.***-' . substr($cpfDigits, -2);
        $referenceDate = date('Y-m-d');

        try {
            $stmt = $db->prepare(
                'INSERT INTO debtor_events (
                    client_id, unit_id, event_type, external_member_id, cpf_hash, cpf_mask,
                    customer_name, debt_amount, debt_age_days, checkout_link, reference_date, payload_json
                ) VALUES (
                    :client_id, :unit_id, :event_type, :external_member_id, :cpf_hash, :cpf_mask,
                    :customer_name, :debt_amount, :debt_age_days, :checkout_link, :reference_date, :payload_json
                )
                ON DUPLICATE KEY UPDATE
                    customer_name = VALUES(customer_name),
                    debt_amount = VALUES(debt_amount),
                    debt_age_days = VALUES(debt_age_days),
                    checkout_link = VALUES(checkout_link),
                    payload_json = VALUES(payload_json)'
            );

            $stmt->execute([
                ':client_id' => (int) ($unitContext['client_id'] ?? 0),
                ':unit_id' => (int) ($unitContext['unit_id'] ?? 0),
                ':event_type' => 'PAYMENT_LINK_SENT',
                ':external_member_id' => (int) ($result['memberId'] ?? 0) ?: null,
                ':cpf_hash' => $cpfHash,
                ':cpf_mask' => $cpfMask,
                ':customer_name' => (string) ($result['nome_cliente'] ?? '') ?: null,
                ':debt_amount' => (float) ($result['debtAmount'] ?? 0),
                ':debt_age_days' => (int) ($result['dias_atraso_atual'] ?? 0),
                ':checkout_link' => $checkoutLink,
                ':reference_date' => $referenceDate,
                ':payload_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {
            // Falha no evento nao derruba a API.
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function getJsonBody(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($raw, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];
        return $this->jsonBody;
    }

    private function extractAccessToken(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!is_array($headers)) {
            $headers = [];
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_scalar($value) ? trim((string) $value) : '';
        }

        if (($normalized['x-api-key'] ?? '') !== '') {
            return $normalized['x-api-key'];
        }

        $auth = $normalized['authorization'] ?? (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        return '';
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
