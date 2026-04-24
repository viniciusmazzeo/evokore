<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use EvoKore\Security\RateLimiter;
use EvoKore\Support\RequestLogger;
use PDO;
use Throwable;

final class WebhookController
{
    /**
     * @var array<string,mixed>
     */
    private array $app;

    /**
     * @param array<string,mixed> $app
     */
    public function __construct(array $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $requestId = bin2hex(random_bytes(8));
        $ip = $this->resolveClientIp();
        $headers = $this->getAllHeadersSafe();
        $route = '/webhook/evo';
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'POST');
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false) {
            $this->log($requestId, $ip, $method, $route, $headers, '', null, 'invalid_body', 400);
            $this->json(400, ['error' => 'Invalid request body']);
            return;
        }

        $maxBodyBytes = (int) ($this->app['config']['webhook']['max_body_bytes'] ?? 1048576);
        if (strlen($rawBody) > $maxBodyBytes) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'payload_too_large', 413);
            $this->json(413, ['error' => 'Payload too large']);
            return;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($contentType !== '' && strpos($contentType, 'application/json') === false) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'invalid_content_type', 415);
            $this->json(415, ['error' => 'Unsupported Media Type']);
            return;
        }

        $limiter = new RateLimiter(
            (string) ($this->app['paths']['rate_limit'] ?? __DIR__ . '/../../storage/ratelimit'),
            (int) ($this->app['config']['webhook']['rate_limit']['max_requests'] ?? 60),
            (int) ($this->app['config']['webhook']['rate_limit']['window_seconds'] ?? 60)
        );

        $rateKey = $route . '|' . $ip;
        $rate = $limiter->hit($rateKey);
        if (!$rate['allowed']) {
            header('Retry-After: ' . $rate['retry_after']);
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'rate_limited', 429);
            $this->json(429, ['error' => 'Too Many Requests']);
            return;
        }

        header('X-RateLimit-Remaining: ' . $rate['remaining']);

        $configuredToken = (string) ($this->app['config']['webhook']['token'] ?? '');
        $debugAuth = (bool) ($this->app['config']['webhook']['debug_auth'] ?? false);
        $tokenData = $this->extractToken();
        $requestToken = $tokenData['token'];
        if ($configuredToken === '' || $requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'invalid_token', 401);
            $response = ['error' => 'Unauthorized'];
            if ($debugAuth) {
                $response['auth_debug'] = [
                    'source' => $tokenData['source'],
                    'received_token_len' => strlen($requestToken),
                    'configured_token_len' => strlen($configuredToken),
                    'received_token_hash8' => $requestToken !== '' ? substr(hash('sha256', $requestToken), 0, 8) : '',
                    'configured_token_hash8' => $configuredToken !== '' ? substr(hash('sha256', $configuredToken), 0, 8) : '',
                ];
            }
            $this->json(401, $response);
            return;
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'invalid_json', 400);
            $this->json(400, ['error' => 'Invalid JSON']);
            return;
        }

        if (!is_array($decoded)) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'invalid_payload_type', 400);
            $this->json(400, ['error' => 'JSON object expected']);
            return;
        }

        $sanitized = $this->sanitize($decoded);
        $reconciliation = $this->reconcilePaymentWebhook($sanitized);
        $logPayload = $sanitized;
        $logPayload['_reconciliation'] = $reconciliation;
        $this->log($requestId, $ip, $method, $route, $headers, $rawBody, $logPayload, 'accepted', 200);

        $this->json(200, [
            'ok' => true,
            'request_id' => $requestId,
            'message' => 'Webhook received',
            'reconciliation' => $reconciliation,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
                continue;
            }

            if (is_string($value)) {
                $clean = trim($value);
                $clean = preg_replace('/[[:cntrl:]]/u', '', $clean) ?? '';
                $payload[$key] = $clean;
            }
        }

        return $payload;
    }

    /**
     * @return array{token:string,source:string}
     */
    private function extractToken(): array
    {
        $headers = $this->getAllHeadersSafe();

        $xWebhookToken = $headers['x-webhook-token'] ?? '';
        if (is_string($xWebhookToken) && $xWebhookToken !== '') {
            return ['token' => trim($xWebhookToken), 'source' => 'header:x-webhook-token'];
        }

        $serverToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? $_SERVER['REDIRECT_HTTP_X_WEBHOOK_TOKEN'] ?? '';
        if (is_string($serverToken) && $serverToken !== '') {
            return ['token' => trim($serverToken), 'source' => 'server:http_x_webhook_token'];
        }

        $auth = $headers['authorization'] ?? '';
        if (!is_string($auth) || $auth === '') {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if (!is_string($auth) || $auth === '') {
            return ['token' => '', 'source' => 'none'];
        }

        if (stripos($auth, 'Bearer ') === 0) {
            return ['token' => trim(substr($auth, 7)), 'source' => 'header:authorization-bearer'];
        }

        return ['token' => '', 'source' => 'authorization-without-bearer'];
    }

    /**
     * @return array<string,string>
     */
    private function getAllHeadersSafe(): array
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!is_array($headers)) {
            $headers = [];
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
        }

        return $normalized;
    }

    private function resolveClientIp(): string
    {
        $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (is_string($cfIp) && $cfIp !== '') {
            return $cfIp;
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($remote) && $remote !== '' ? $remote : '0.0.0.0';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function reconcilePaymentWebhook(array $payload): array
    {
        $db = $this->app['db'] ?? null;
        if (!$db instanceof PDO) {
            return ['matched' => false, 'updated' => false, 'reason' => 'db_unavailable'];
        }

        try {
            $this->ensurePlanSalesTable($db);
        } catch (Throwable $e) {
            return ['matched' => false, 'updated' => false, 'reason' => 'schema_error', 'detail' => $e->getMessage()];
        }

        $evoSaleId = $this->extractFirstInt($payload, ['idVenda', 'idSale', 'sale_id', 'saleId', 'id_venda']);
        $evoMemberId = $this->extractFirstInt($payload, ['idCliente', 'idMember', 'memberId', 'idAluno', 'alunoId']);
        $paymentReference = $this->extractFirstString($payload, ['referenceCode', 'reference', 'paymentReference', 'idExternoBoleto']);
        if ($paymentReference !== null) {
            $paymentReference = substr($paymentReference, 0, 100);
        }
        $cpf = $this->extractDigits($this->extractFirstString($payload, ['cpf', 'document', 'documento', 'memberDocument']));
        $planName = $this->extractFirstString($payload, ['plan_name', 'planName', 'membershipName', 'dsContrato', 'contractName']);

        $rawStatus = $this->extractFirstString($payload, [
            'status',
            'paymentStatus',
            'saleStatus',
            'event',
            'eventType',
            'type',
            'mensagemType',
            'description',
        ]);
        $normalizedStatus = $this->mapWebhookStatus($rawStatus);
        $paidValue = $this->extractFirstFloat($payload, ['paid_value', 'paidValue', 'amountPaid', 'paidAmount', 'valuePaid', 'valorPago', 'amount', 'valor']);
        $paidAt = $this->extractFirstDateTime($payload, ['paid_at', 'paidAt', 'paymentDate', 'dataPagamento', 'dtPagamento', 'createdAt', 'timestamp', 'date']);

        $sale = $this->resolvePlanSaleForWebhook($db, $evoSaleId, $paymentReference, $evoMemberId, $cpf, $planName);
        if ($sale === null) {
            return [
                'matched' => false,
                'updated' => false,
                'reason' => 'sale_not_found',
                'evo_sale_id' => $evoSaleId,
                'payment_reference' => $paymentReference,
                'evo_member_id' => $evoMemberId,
            ];
        }

        $currentStatus = strtoupper((string) ($sale['status'] ?? ''));
        $newStatus = $normalizedStatus ?? $currentStatus;
        $isPaidStatus = in_array($newStatus, ['PAID', 'CONFIRMED'], true);

        if (in_array($currentStatus, ['PAID', 'CONFIRMED'], true) && !$isPaidStatus) {
            $newStatus = $currentStatus;
            $isPaidStatus = true;
        }

        $paidValueToSave = $sale['paid_value'] !== null ? (float) $sale['paid_value'] : null;
        if ($isPaidStatus) {
            if ($paidValue !== null && $paidValue > 0) {
                $paidValueToSave = round($paidValue, 2);
            } elseif ($paidValueToSave === null) {
                $paidValueToSave = isset($sale['plan_value']) ? (float) $sale['plan_value'] : null;
            }
        }

        $paidAtToSave = $sale['paid_at'] ?? null;
        if ($isPaidStatus) {
            $paidAtToSave = $paidAt ?? (is_string($paidAtToSave) && $paidAtToSave !== '' ? $paidAtToSave : date('Y-m-d H:i:s'));
        }

        $statusNote = $rawStatus !== null && trim($rawStatus) !== '' ? substr(trim($rawStatus), 0, 255) : null;

        $stmt = $db->prepare(
            'UPDATE plan_sales
             SET status = :status,
                 paid_value = :paid_value,
                 paid_at = :paid_at,
                 status_note = COALESCE(:status_note, status_note),
                 evo_sale_id = COALESCE(evo_sale_id, :evo_sale_id),
                 evo_member_id = COALESCE(evo_member_id, :evo_member_id),
                 payment_reference = COALESCE(payment_reference, :payment_reference),
                 last_webhook_at = NOW(),
                 last_webhook_payload_json = :last_webhook_payload_json,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            ':status' => $newStatus,
            ':paid_value' => $paidValueToSave,
            ':paid_at' => $paidAtToSave,
            ':status_note' => $statusNote,
            ':evo_sale_id' => $evoSaleId,
            ':evo_member_id' => $evoMemberId,
            ':payment_reference' => $paymentReference,
            ':last_webhook_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => (int) $sale['id'],
        ]);

        return [
            'matched' => true,
            'updated' => true,
            'sale_id' => (int) $sale['id'],
            'matched_by' => (string) ($sale['_matched_by'] ?? 'unknown'),
            'previous_status' => $currentStatus,
            'new_status' => $newStatus,
            'paid_value' => $paidValueToSave,
            'paid_at' => $paidAtToSave,
        ];
    }

    private function ensurePlanSalesTable(PDO $db): void
    {
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS paid_value DECIMAL(12,2) NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS status_note VARCHAR(255) NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS evo_sale_id BIGINT NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS evo_member_id BIGINT NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS last_webhook_at DATETIME NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS last_webhook_payload_json JSON NULL');
        try {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_plan_sales_evo_sale_id ON plan_sales (evo_sale_id)');
        } catch (Throwable) {
            // Ignora quando o banco nao suporta IF NOT EXISTS para indice.
        }
        try {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_plan_sales_evo_member_id ON plan_sales (evo_member_id)');
        } catch (Throwable) {
            // Ignora quando o banco nao suporta IF NOT EXISTS para indice.
        }
        try {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_plan_sales_payment_reference ON plan_sales (payment_reference)');
        } catch (Throwable) {
            // Ignora quando o banco nao suporta IF NOT EXISTS para indice.
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolvePlanSaleForWebhook(
        PDO $db,
        ?int $evoSaleId,
        ?string $paymentReference,
        ?int $evoMemberId,
        string $cpf,
        ?string $planName
    ): ?array {
        if ($evoSaleId !== null && $evoSaleId > 0) {
            $stmt = $db->prepare('SELECT * FROM plan_sales WHERE evo_sale_id = :evo_sale_id ORDER BY id DESC LIMIT 1');
            $stmt->execute([':evo_sale_id' => $evoSaleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $row['_matched_by'] = 'evo_sale_id';
                return $row;
            }
        }

        if ($paymentReference !== null && $paymentReference !== '') {
            $stmt = $db->prepare('SELECT * FROM plan_sales WHERE payment_reference = :payment_reference ORDER BY id DESC LIMIT 1');
            $stmt->execute([':payment_reference' => $paymentReference]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $row['_matched_by'] = 'payment_reference';
                return $row;
            }
        }

        if ($evoMemberId !== null && $evoMemberId > 0) {
            $stmt = $db->prepare('SELECT * FROM plan_sales WHERE evo_member_id = :evo_member_id ORDER BY id DESC LIMIT 1');
            $stmt->execute([':evo_member_id' => $evoMemberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $row['_matched_by'] = 'evo_member_id';
                return $row;
            }
        }

        if (strlen($cpf) === 11 && $planName !== null && trim($planName) !== '') {
            $stmt = $db->prepare(
                'SELECT * FROM plan_sales
                 WHERE cpf = :cpf AND plan_name = :plan_name
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute([
                ':cpf' => $cpf,
                ':plan_name' => trim($planName),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $row['_matched_by'] = 'cpf_plan_name';
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $keys
     */
    private function extractFirstInt(array $payload, array $keys): ?int
    {
        $value = $this->extractFirstScalar($payload, $keys);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        $int = (int) $digits;
        return $int > 0 ? $int : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $keys
     */
    private function extractFirstFloat(array $payload, array $keys): ?float
    {
        $value = $this->extractFirstScalar($payload, $keys);
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(['R$', ' '], '', (string) $value);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $keys
     */
    private function extractFirstString(array $payload, array $keys): ?string
    {
        $value = $this->extractFirstScalar($payload, $keys);
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text !== '' ? $text : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $keys
     */
    private function extractFirstDateTime(array $payload, array $keys): ?string
    {
        $value = $this->extractFirstString($payload, $keys);
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $keys
     * @return scalar|null
     */
    private function extractFirstScalar(array $payload, array $keys)
    {
        $wanted = array_map('strtolower', $keys);
        $stack = [$payload];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            foreach ($current as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), $wanted, true) && is_scalar($value)) {
                    return $value;
                }

                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return null;
    }

    private function extractDigits(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function mapWebhookStatus(?string $rawStatus): ?string
    {
        if ($rawStatus === null || trim($rawStatus) === '') {
            return null;
        }

        $status = mb_strtoupper(trim($rawStatus), 'UTF-8');

        $paidMarkers = ['PAID', 'PAGO', 'APPROVED', 'APROVADO', 'CONFIRMED', 'CONFIRMADO', 'RECEIVED', 'QUITADO', 'LIQUIDADO', 'BAIXADO', 'SUCCESS', 'SUCESSO'];
        foreach ($paidMarkers as $marker) {
            if (str_contains($status, $marker)) {
                return 'PAID';
            }
        }

        $pendingMarkers = ['PENDING', 'AGUARD', 'PROCESS', 'ANALISE', 'ANÁLISE'];
        foreach ($pendingMarkers as $marker) {
            if (str_contains($status, $marker)) {
                return 'PENDING';
            }
        }

        $cancelMarkers = ['CANCEL', 'ESTORN', 'REFUND'];
        foreach ($cancelMarkers as $marker) {
            if (str_contains($status, $marker)) {
                return 'CANCELED';
            }
        }

        $failedMarkers = ['FAILED', 'ERRO', 'ERROR', 'DENIED', 'RECUS', 'EXPIR'];
        foreach ($failedMarkers as $marker) {
            if (str_contains($status, $marker)) {
                return 'FAILED';
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $sanitizedPayload
     */
    private function log(
        string $requestId,
        string $ip,
        string $method,
        string $route,
        array $headers,
        string $rawBody,
        ?array $sanitizedPayload,
        string $status,
        int $httpStatus
    ): void {
        $redactedHeaders = $headers;
        if (isset($redactedHeaders['authorization'])) {
            $redactedHeaders['authorization'] = '[REDACTED]';
        }
        if (isset($redactedHeaders['x-webhook-token'])) {
            $redactedHeaders['x-webhook-token'] = '[REDACTED]';
        }

        $logger = new RequestLogger((string) ($this->app['paths']['logs'] ?? __DIR__ . '/../../storage/logs') . '/webhook.log');
        $logger->write([
            'timestamp' => gmdate('c'),
            'request_id' => $requestId,
            'ip' => $ip,
            'method' => $method,
            'route' => $route,
            'status' => $status,
            'http_status' => $httpStatus,
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'headers' => $redactedHeaders,
            'raw_body' => $rawBody,
            'sanitized_payload' => $sanitizedPayload,
        ]);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(int $statusCode, array $body): void
    {
        http_response_code($statusCode);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
