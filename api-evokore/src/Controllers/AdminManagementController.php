<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use PDO;
use RuntimeException;
use Throwable;

final class AdminManagementController
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

    public function handle(string $method, string $path): void
    {
        if (!$this->isAuthorized()) {
            $this->json(401, ['error' => 'Unauthorized']);
            return;
        }

        $db = $this->getDb();

        try {
            if ($path === '/admin/clients' && $method === 'GET') {
                $this->listClients($db);
                return;
            }
            if ($path === '/admin/clients' && $method === 'POST') {
                $this->createClient($db);
                return;
            }
            if (preg_match('#^/admin/clients/(\d+)$#', $path, $m) === 1) {
                $id = (int) $m[1];
                if ($method === 'GET') {
                    $this->getClient($db, $id);
                    return;
                }
                if ($method === 'PUT' || $method === 'PATCH') {
                    $this->updateClient($db, $id);
                    return;
                }
                $this->methodNotAllowed(['GET', 'PUT', 'PATCH']);
                return;
            }

            if ($path === '/admin/units' && $method === 'GET') {
                $this->listUnits($db);
                return;
            }
            if ($path === '/admin/units' && $method === 'POST') {
                $this->createUnit($db);
                return;
            }
            if (preg_match('#^/admin/units/(\d+)$#', $path, $m) === 1) {
                $id = (int) $m[1];
                if ($method === 'GET') {
                    $this->getUnit($db, $id);
                    return;
                }
                if ($method === 'PUT' || $method === 'PATCH') {
                    $this->updateUnit($db, $id);
                    return;
                }
                $this->methodNotAllowed(['GET', 'PUT', 'PATCH']);
                return;
            }
            if (preg_match('#^/admin/units/(\d+)/access-token$#', $path, $m) === 1) {
                $unitId = (int) $m[1];
                if ($method === 'GET') {
                    $this->getUnitAccessToken($db, $unitId);
                    return;
                }
                if ($method === 'POST') {
                    $this->rotateUnitAccessToken($db, $unitId);
                    return;
                }
                $this->methodNotAllowed(['GET', 'POST']);
                return;
            }

            if ($path === '/admin/unit-credentials' && $method === 'GET') {
                $this->listUnitCredentials($db);
                return;
            }
            if ($path === '/admin/unit-credentials' && $method === 'POST') {
                $this->createUnitCredential($db);
                return;
            }
            if (preg_match('#^/admin/unit-credentials/(\d+)$#', $path, $m) === 1) {
                $id = (int) $m[1];
                if ($method === 'GET') {
                    $this->getUnitCredential($db, $id);
                    return;
                }
                if ($method === 'PUT' || $method === 'PATCH') {
                    $this->updateUnitCredential($db, $id);
                    return;
                }
                $this->methodNotAllowed(['GET', 'PUT', 'PATCH']);
                return;
            }

            if ($path === '/admin/logs' && $method === 'GET') {
                $this->listIntegrationLogs($db);
                return;
            }

            $this->json(404, ['error' => 'Not Found']);
        } catch (Throwable $e) {
            $this->json(500, ['error' => 'Internal Server Error', 'message' => $e->getMessage()]);
        }
    }

    private function listClients(PDO $db): void
    {
        $where = [];
        $params = [];

        $status = strtoupper(trim((string) ($_GET['status'] ?? '')));
        if ($status === 'ACTIVE' || $status === 'INACTIVE') {
            $where[] = 'c.status = :status';
            $params[':status'] = $status;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.name LIKE :q OR c.legal_name LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $sql = 'SELECT c.* FROM clients c';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.name ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $this->json(200, ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private function createClient(PDO $db): void
    {
        $data = $this->readJsonBody();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $this->json(400, ['error' => 'name is required']);
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO clients (name, legal_name, document_type, document_number, status)
             VALUES (:name, :legal_name, :document_type, :document_number, :status)'
        );
        $stmt->execute([
            ':name' => $name,
            ':legal_name' => $this->nullableString($data['legal_name'] ?? null),
            ':document_type' => $this->enumOrDefault((string) ($data['document_type'] ?? ''), ['CNPJ', 'CPF', 'OTHER'], 'CNPJ'),
            ':document_number' => $this->nullableString($data['document_number'] ?? null),
            ':status' => $this->enumOrDefault((string) ($data['status'] ?? ''), ['ACTIVE', 'INACTIVE'], 'ACTIVE'),
        ]);

        $this->getClient($db, (int) $db->lastInsertId(), 201);
    }

    private function getClient(PDO $db, int $id, int $statusCode = 200): void
    {
        $stmt = $db->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->json(404, ['error' => 'Client not found']);
            return;
        }

        $this->json($statusCode, ['data' => $row]);
    }

    private function updateClient(PDO $db, int $id): void
    {
        $stmt = $db->prepare('SELECT id FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->json(404, ['error' => 'Client not found']);
            return;
        }

        $data = $this->readJsonBody();
        $stmt = $db->prepare(
            'UPDATE clients
             SET name = :name,
                 legal_name = :legal_name,
                 document_type = :document_type,
                 document_number = :document_number,
                 status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => trim((string) ($data['name'] ?? '')),
            ':legal_name' => $this->nullableString($data['legal_name'] ?? null),
            ':document_type' => $this->enumOrDefault((string) ($data['document_type'] ?? ''), ['CNPJ', 'CPF', 'OTHER'], 'CNPJ'),
            ':document_number' => $this->nullableString($data['document_number'] ?? null),
            ':status' => $this->enumOrDefault((string) ($data['status'] ?? ''), ['ACTIVE', 'INACTIVE'], 'ACTIVE'),
        ]);

        $this->getClient($db, $id);
    }

    private function listUnits(PDO $db): void
    {
        $where = [];
        $params = [];

        $clientId = (int) ($_GET['client_id'] ?? 0);
        if ($clientId > 0) {
            $where[] = 'u.client_id = :client_id';
            $params[':client_id'] = $clientId;
        }

        $status = strtoupper(trim((string) ($_GET['status'] ?? '')));
        if ($status === 'ACTIVE' || $status === 'INACTIVE') {
            $where[] = 'u.status = :status';
            $params[':status'] = $status;
        }

        $sql = 'SELECT u.*, c.name AS client_name
                FROM units u
                INNER JOIN clients c ON c.id = u.client_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.name ASC, u.unit_name ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $this->json(200, ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private function createUnit(PDO $db): void
    {
        $data = $this->readJsonBody();
        $clientId = (int) ($data['client_id'] ?? 0);
        $unitCode = trim((string) ($data['unit_code'] ?? ''));
        $unitName = trim((string) ($data['unit_name'] ?? ''));

        if ($clientId <= 0 || $unitCode === '' || $unitName === '') {
            $this->json(400, ['error' => 'client_id, unit_code and unit_name are required']);
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO units (client_id, unit_code, unit_name, status, timezone)
             VALUES (:client_id, :unit_code, :unit_name, :status, :timezone)'
        );
        $stmt->execute([
            ':client_id' => $clientId,
            ':unit_code' => $unitCode,
            ':unit_name' => $unitName,
            ':status' => $this->enumOrDefault((string) ($data['status'] ?? ''), ['ACTIVE', 'INACTIVE'], 'ACTIVE'),
            ':timezone' => trim((string) ($data['timezone'] ?? 'America/Sao_Paulo')),
        ]);

        $this->getUnit($db, (int) $db->lastInsertId(), 201);
    }

    private function getUnit(PDO $db, int $id, int $statusCode = 200): void
    {
        $stmt = $db->prepare(
            'SELECT u.*, c.name AS client_name
             FROM units u
             INNER JOIN clients c ON c.id = u.client_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->json(404, ['error' => 'Unit not found']);
            return;
        }

        $this->json($statusCode, ['data' => $row]);
    }

    private function updateUnit(PDO $db, int $id): void
    {
        $stmt = $db->prepare('SELECT id FROM units WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->json(404, ['error' => 'Unit not found']);
            return;
        }

        $data = $this->readJsonBody();
        $stmt = $db->prepare(
            'UPDATE units
             SET client_id = :client_id,
                 unit_code = :unit_code,
                 unit_name = :unit_name,
                 status = :status,
                 timezone = :timezone
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':client_id' => (int) ($data['client_id'] ?? 0),
            ':unit_code' => trim((string) ($data['unit_code'] ?? '')),
            ':unit_name' => trim((string) ($data['unit_name'] ?? '')),
            ':status' => $this->enumOrDefault((string) ($data['status'] ?? ''), ['ACTIVE', 'INACTIVE'], 'ACTIVE'),
            ':timezone' => trim((string) ($data['timezone'] ?? 'America/Sao_Paulo')),
        ]);

        $this->getUnit($db, $id);
    }

    private function getUnitAccessToken(PDO $db, int $unitId): void
    {
        $stmt = $db->prepare(
            'SELECT
                t.id,
                t.unit_id,
                u.unit_name,
                u.unit_code,
                t.token_hint,
                t.is_active,
                t.expires_at,
                t.last_used_at,
                t.rotated_at,
                t.created_at,
                t.updated_at
             FROM unit_api_tokens t
             INNER JOIN units u ON u.id = t.unit_id
             WHERE t.unit_id = :unit_id AND t.is_active = 1
             ORDER BY t.updated_at DESC
             LIMIT 1'
        );
        $stmt->execute([':unit_id' => $unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->json(404, ['error' => 'Active unit access token not found']);
            return;
        }

        $this->json(200, ['data' => $row]);
    }

    private function rotateUnitAccessToken(PDO $db, int $unitId): void
    {
        $stmt = $db->prepare('SELECT id, unit_name, unit_code FROM units WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$unit) {
            $this->json(404, ['error' => 'Unit not found']);
            return;
        }

        $payload = $this->readJsonBody();
        $expiresAt = $this->nullableString($payload['expires_at'] ?? null);
        if ($expiresAt !== null && !$this->isValidDateTime($expiresAt)) {
            $this->json(400, ['error' => 'expires_at must be valid DATETIME (Y-m-d H:i:s)']);
            return;
        }

        $plainToken = $this->generateUnitAccessToken();
        $tokenHash = hash('sha256', $plainToken);
        $tokenHint = $this->tokenHint($plainToken);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE unit_api_tokens SET is_active = 0 WHERE unit_id = :unit_id');
            $stmt->execute([':unit_id' => $unitId]);

            $stmt = $db->prepare(
                'INSERT INTO unit_api_tokens (
                    unit_id,
                    token_hash,
                    token_hint,
                    is_active,
                    expires_at,
                    rotated_at
                 ) VALUES (
                    :unit_id,
                    :token_hash,
                    :token_hint,
                    1,
                    :expires_at,
                    NOW()
                 )'
            );
            $stmt->execute([
                ':unit_id' => $unitId,
                ':token_hash' => $tokenHash,
                ':token_hint' => $tokenHint,
                ':expires_at' => $expiresAt,
            ]);

            $tokenId = (int) $db->lastInsertId();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $this->json(201, [
            'data' => [
                'id' => $tokenId,
                'unit_id' => $unitId,
                'unit_name' => (string) $unit['unit_name'],
                'unit_code' => (string) $unit['unit_code'],
                'token_hint' => $tokenHint,
                'expires_at' => $expiresAt,
                'is_active' => 1,
                'token' => $plainToken,
            ],
        ]);
    }

    private function listUnitCredentials(PDO $db): void
    {
        $where = [];
        $params = [];

        $unitId = (int) ($_GET['unit_id'] ?? 0);
        if ($unitId > 0) {
            $where[] = 'uc.unit_id = :unit_id';
            $params[':unit_id'] = $unitId;
        }

        $isActive = $_GET['is_active'] ?? null;
        if ($isActive !== null && ($isActive === '0' || $isActive === '1')) {
            $where[] = 'uc.is_active = :is_active';
            $params[':is_active'] = (int) $isActive;
        }

        $sql = 'SELECT
                    uc.id,
                    uc.unit_id,
                    u.unit_name,
                    u.unit_code,
                    uc.evo_dns,
                    uc.token_hint,
                    uc.is_active,
                    uc.rotated_at,
                    uc.created_at,
                    uc.updated_at
                FROM unit_evo_credentials uc
                INNER JOIN units u ON u.id = uc.unit_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY uc.unit_id ASC, uc.updated_at DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $this->json(200, ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private function createUnitCredential(PDO $db): void
    {
        $data = $this->readJsonBody();
        $unitId = (int) ($data['unit_id'] ?? 0);
        $token = trim((string) ($data['token'] ?? ''));
        $evoDns = trim((string) ($data['evo_dns'] ?? ''));
        $isActive = (int) ($data['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($unitId <= 0 || $token === '' || $evoDns === '') {
            $this->json(400, ['error' => 'unit_id, evo_dns and token are required']);
            return;
        }

        if ($isActive === 1) {
            $stmt = $db->prepare('UPDATE unit_evo_credentials SET is_active = 0 WHERE unit_id = :unit_id');
            $stmt->execute([':unit_id' => $unitId]);
        }

        $tokenHint = $this->tokenHint($token);
        $stmt = $db->prepare(
            'INSERT INTO unit_evo_credentials (unit_id, evo_dns, token_encrypted, token_hint, is_active, rotated_at)
             VALUES (:unit_id, :evo_dns, :token_encrypted, :token_hint, :is_active, NOW())'
        );
        $stmt->execute([
            ':unit_id' => $unitId,
            ':evo_dns' => $evoDns,
            ':token_encrypted' => $token,
            ':token_hint' => $tokenHint,
            ':is_active' => $isActive,
        ]);

        $this->getUnitCredential($db, (int) $db->lastInsertId(), 201);
    }

    private function getUnitCredential(PDO $db, int $id, int $statusCode = 200): void
    {
        $stmt = $db->prepare(
            'SELECT
                uc.id,
                uc.unit_id,
                u.unit_name,
                u.unit_code,
                uc.evo_dns,
                uc.token_hint,
                uc.is_active,
                uc.rotated_at,
                uc.created_at,
                uc.updated_at
             FROM unit_evo_credentials uc
             INNER JOIN units u ON u.id = uc.unit_id
             WHERE uc.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->json(404, ['error' => 'Unit credential not found']);
            return;
        }

        $this->json($statusCode, ['data' => $row]);
    }

    private function updateUnitCredential(PDO $db, int $id): void
    {
        $stmt = $db->prepare('SELECT id, unit_id FROM unit_evo_credentials WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            $this->json(404, ['error' => 'Unit credential not found']);
            return;
        }

        $data = $this->readJsonBody();
        $unitId = (int) ($data['unit_id'] ?? (int) $current['unit_id']);
        $token = trim((string) ($data['token'] ?? ''));
        $isActive = (int) ($data['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($unitId <= 0) {
            $this->json(400, ['error' => 'unit_id is required']);
            return;
        }

        if ($isActive === 1) {
            $stmt = $db->prepare('UPDATE unit_evo_credentials SET is_active = 0 WHERE unit_id = :unit_id');
            $stmt->execute([':unit_id' => $unitId]);
        }

        $stmt = $db->prepare(
            'UPDATE unit_evo_credentials
             SET unit_id = :unit_id,
                 evo_dns = :evo_dns,
                 token_encrypted = CASE WHEN :token = \'\' THEN token_encrypted ELSE :token END,
                 token_hint = CASE WHEN :token = \'\' THEN token_hint ELSE :token_hint END,
                 is_active = :is_active,
                 rotated_at = CASE WHEN :token = \'\' THEN rotated_at ELSE NOW() END
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':unit_id' => $unitId,
            ':evo_dns' => trim((string) ($data['evo_dns'] ?? '')),
            ':token' => $token,
            ':token_hint' => $token !== '' ? $this->tokenHint($token) : null,
            ':is_active' => $isActive,
        ]);

        $this->getUnitCredential($db, $id);
    }

    private function listIntegrationLogs(PDO $db): void
    {
        $where = [];
        $params = [];

        $clientId = (int) ($_GET['client_id'] ?? 0);
        if ($clientId > 0) {
            $where[] = 'l.client_id = :client_id';
            $params[':client_id'] = $clientId;
        }

        $unitId = (int) ($_GET['unit_id'] ?? 0);
        if ($unitId > 0) {
            $where[] = 'l.unit_id = :unit_id';
            $params[':unit_id'] = $unitId;
        }

        $provider = strtoupper(trim((string) ($_GET['provider'] ?? '')));
        if (in_array($provider, ['EVO', 'N8N', 'SYSTEM'], true)) {
            $where[] = 'l.provider = :provider';
            $params[':provider'] = $provider;
        }

        $success = $_GET['success'] ?? null;
        if ($success === '0' || $success === '1') {
            $where[] = 'l.success = :success';
            $params[':success'] = (int) $success;
        }

        $httpStatus = (int) ($_GET['http_status'] ?? 0);
        if ($httpStatus > 0) {
            $where[] = 'l.http_status = :http_status';
            $params[':http_status'] = $httpStatus;
        }

        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'l.created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'l.created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(l.endpoint LIKE :q OR l.error_message LIKE :q OR l.request_id LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $perPage = (int) ($_GET['per_page'] ?? 20);
        if ($perPage <= 0) {
            $perPage = 20;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $page = (int) ($_GET['page'] ?? 1);
        if ($page <= 0) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;

        $fromSql = '
            FROM integration_logs l
            LEFT JOIN clients c ON c.id = l.client_id
            LEFT JOIN units u ON u.id = l.unit_id
        ';
        if ($where !== []) {
            $fromSql .= ' WHERE ' . implode(' AND ', $where);
        }

        $countSql = 'SELECT COUNT(*) ' . $fromSql;
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = '
            SELECT
                l.id,
                l.client_id,
                c.name AS client_name,
                l.unit_id,
                u.unit_name,
                u.unit_code,
                l.provider,
                l.endpoint,
                l.method,
                l.http_status,
                l.latency_ms,
                l.request_id,
                l.success,
                l.error_code,
                l.error_message,
                l.meta_json,
                l.created_at
        ' . $fromSql . '
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT :limit OFFSET :offset
        ';

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->json(200, [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    private function isAuthorized(): bool
    {
        $expected = (string) ($this->app['env']['ADMIN_API_TOKEN'] ?? ($this->app['env']['FINANCIAL_ENDPOINT_TOKEN'] ?? ''));
        if ($expected === '') {
            return false;
        }

        $provided = $this->extractAccessToken();
        return $provided !== '' && hash_equals($expected, $provided);
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
     * @return array<string,mixed>
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getDb(): PDO
    {
        $db = $this->app['db'] ?? null;
        if (!$db instanceof PDO) {
            throw new RuntimeException('Database unavailable');
        }

        return $db;
    }

    /**
     * @param string[] $allowed
     */
    private function methodNotAllowed(array $allowed): void
    {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowed));
        echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    /**
     * @param string[] $allowed
     */
    private function enumOrDefault(string $value, array $allowed, string $default): string
    {
        $normalized = strtoupper(trim($value));
        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }

    private function tokenHint(string $token): string
    {
        $len = strlen($token);
        if ($len <= 4) {
            return '***' . $token;
        }
        return '...' . substr($token, -4);
    }

    private function generateUnitAccessToken(): string
    {
        $random = bin2hex(random_bytes(24));
        return 'eku_' . $random;
    }

    private function isValidDateTime(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        return $dt instanceof \DateTimeImmutable && $dt->format('Y-m-d H:i:s') === $value;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(int $statusCode, array $body): void
    {
        http_response_code($statusCode);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
