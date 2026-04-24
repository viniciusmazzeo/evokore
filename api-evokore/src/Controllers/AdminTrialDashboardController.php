<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use PDO;
use Throwable;

final class AdminTrialDashboardController extends AdminController
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
            $this->ensureTable($db);
            $range = $this->parseDateRange();
            $start = $range['start']->format('Y-m-d 00:00:00');
            $end = $this->endDateExclusive($range['end'])->format('Y-m-d 00:00:00');

            $clientId = $this->parsePositiveInt((string) ($_GET['client_id'] ?? ''));
            $unitId = $this->parsePositiveInt((string) ($_GET['unit_id'] ?? ''));

            $where = ['created_at >= :start_date', 'created_at < :end_date'];
            $params = [':start_date' => $start, ':end_date' => $end];
            if ($clientId !== null) {
                $where[] = 'client_id = :client_id';
                $params[':client_id'] = $clientId;
            }
            if ($unitId !== null) {
                $where[] = 'unit_id = :unit_id';
                $params[':unit_id'] = $unitId;
            }
            $whereSql = implode(' AND ', $where);

            $summaryStmt = $db->prepare(
                "SELECT
                    COUNT(*) AS total_trials,
                    COUNT(CASE WHEN status IN ('SCHEDULED','CONFIRMED') THEN 1 END) AS scheduled_trials,
                    COUNT(CASE WHEN status IN ('DONE','COMPLETED') THEN 1 END) AS completed_trials,
                    COUNT(CASE WHEN status IN ('CANCELED','CANCELLED') THEN 1 END) AS canceled_trials
                 FROM trial_classes
                 WHERE {$whereSql}"
            );
            $summaryStmt->execute($params);
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $rowsStmt = $db->prepare(
                "SELECT
                    t.unit_id,
                    COALESCE(u.unit_name, CONCAT('Unidade #', t.unit_id)) AS unit_name,
                    COUNT(*) AS total_trials,
                    COUNT(CASE WHEN t.status IN ('SCHEDULED','CONFIRMED') THEN 1 END) AS scheduled_trials,
                    COUNT(CASE WHEN t.status IN ('DONE','COMPLETED') THEN 1 END) AS completed_trials,
                    COUNT(CASE WHEN t.status IN ('CANCELED','CANCELLED') THEN 1 END) AS canceled_trials
                 FROM trial_classes t
                 LEFT JOIN units u ON u.id = t.unit_id
                 WHERE t.created_at >= :start_date AND t.created_at < :end_date" .
                ($clientId !== null ? ' AND t.client_id = :client_id' : '') .
                ($unitId !== null ? ' AND t.unit_id = :unit_id' : '') .
                ' GROUP BY t.unit_id, u.unit_name
                  ORDER BY total_trials DESC'
            );
            $rowsStmt->execute($params);
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            $totalTrials = (int) ($summary['total_trials'] ?? 0);
            $completed = (int) ($summary['completed_trials'] ?? 0);

            $this->json(200, [
                'data' => [
                    'summary' => [
                        'total_trials' => $totalTrials,
                        'scheduled_trials' => (int) ($summary['scheduled_trials'] ?? 0),
                        'completed_trials' => $completed,
                        'canceled_trials' => (int) ($summary['canceled_trials'] ?? 0),
                        'completion_rate' => $totalTrials > 0 ? round(($completed / $totalTrials) * 100, 2) : 0,
                    ],
                    'by_unit' => array_map(static function (array $row): array {
                        $total = (int) ($row['total_trials'] ?? 0);
                        $completedRow = (int) ($row['completed_trials'] ?? 0);
                        return [
                            'unit_id' => (int) ($row['unit_id'] ?? 0),
                            'unit_name' => (string) ($row['unit_name'] ?? ''),
                            'total_trials' => $total,
                            'scheduled_trials' => (int) ($row['scheduled_trials'] ?? 0),
                            'completed_trials' => $completedRow,
                            'canceled_trials' => (int) ($row['canceled_trials'] ?? 0),
                            'completion_rate' => $total > 0 ? round(($completedRow / $total) * 100, 2) : 0,
                        ];
                    }, $rows),
                ],
            ]);
        } catch (Throwable $e) {
            $this->json(500, ['error' => 'Internal Server Error', 'detail' => $e->getMessage()]);
        }
    }

    private function ensureTable(PDO $db): void
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
                status VARCHAR(30) NOT NULL DEFAULT "SCHEDULED",
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
    }
}
