<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use PDO;
use Throwable;

final class AdminUnitsController extends AdminController
{
    public function handle(): void
    {
        if (!$this->requireAdminToken()) {
            return;
        }

        $db = $this->db();
        if ($db === null) {
            $this->json(503, ['error' => 'Database unavailable']);
            return;
        }

        try {
            $clientId = $this->parsePositiveInt((string) ($_GET['client_id'] ?? ''));

            $sql = 'SELECT id, client_id, unit_code, unit_name, status, timezone, created_at, updated_at
                    FROM units';
            $params = [];
            if ($clientId !== null) {
                $sql .= ' WHERE client_id = :client_id';
                $params[':client_id'] = $clientId;
            }
            $sql .= ' ORDER BY unit_name ASC';

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->json(200, ['data' => $rows]);
        } catch (Throwable $e) {
            $this->json(500, ['error' => 'Internal Server Error', 'detail' => $e->getMessage()]);
        }
    }
}

