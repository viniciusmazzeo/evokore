<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use PDO;
use Throwable;

final class AdminClientsController extends AdminController
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
            $stmt = $db->query(
                'SELECT id, name, legal_name, status, created_at, updated_at
                 FROM clients
                 ORDER BY name ASC'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->json(200, ['data' => $rows]);
        } catch (Throwable $e) {
            $this->json(500, ['error' => 'Internal Server Error', 'detail' => $e->getMessage()]);
        }
    }
}

