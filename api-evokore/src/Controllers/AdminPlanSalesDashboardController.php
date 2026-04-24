<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use PDO;
use Throwable;

final class AdminPlanSalesDashboardController extends AdminController
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
                    COUNT(*) AS total_sales,
                    COALESCE(SUM(plan_value), 0) AS total_value,
                    COUNT(CASE WHEN status IN ('PAID','CONFIRMED') THEN 1 END) AS paid_sales,
                    COALESCE(SUM(CASE WHEN status IN ('PAID','CONFIRMED') THEN COALESCE(paid_value, plan_value) ELSE 0 END), 0) AS paid_value,
                    COUNT(CASE WHEN payment_link IS NOT NULL AND payment_link <> '' THEN 1 END) AS links_generated
                 FROM plan_sales
                 WHERE {$whereSql}"
            );
            $summaryStmt->execute($params);
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $rowsStmt = $db->prepare(
                "SELECT
                    p.unit_id,
                    COALESCE(u.unit_name, CONCAT('Unidade #', p.unit_id)) AS unit_name,
                    COUNT(*) AS total_sales,
                    COALESCE(SUM(p.plan_value), 0) AS total_value,
                    COUNT(CASE WHEN p.status IN ('PAID','CONFIRMED') THEN 1 END) AS paid_sales,
                    COALESCE(SUM(CASE WHEN p.status IN ('PAID','CONFIRMED') THEN COALESCE(p.paid_value, p.plan_value) ELSE 0 END), 0) AS paid_value,
                    COUNT(CASE WHEN p.payment_link IS NOT NULL AND p.payment_link <> '' THEN 1 END) AS links_generated
                 FROM plan_sales p
                 LEFT JOIN units u ON u.id = p.unit_id
                 WHERE p.created_at >= :start_date AND p.created_at < :end_date" .
                ($clientId !== null ? ' AND p.client_id = :client_id' : '') .
                ($unitId !== null ? ' AND p.unit_id = :unit_id' : '') .
                ' GROUP BY p.unit_id, u.unit_name
                  ORDER BY paid_value DESC, paid_sales DESC'
            );
            $rowsStmt->execute($params);
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            $totalSales = (int) ($summary['total_sales'] ?? 0);
            $paidSales = (int) ($summary['paid_sales'] ?? 0);
            $totalValue = (float) ($summary['total_value'] ?? 0);
            $paidValue = (float) ($summary['paid_value'] ?? 0);

            $this->json(200, [
                'data' => [
                    'summary' => [
                        'total_sales' => $totalSales,
                        'total_value' => round($totalValue, 2),
                        'paid_sales' => $paidSales,
                        'paid_value' => round($paidValue, 2),
                        'links_generated' => (int) ($summary['links_generated'] ?? 0),
                        'conversion_by_count' => $totalSales > 0 ? round(($paidSales / $totalSales) * 100, 2) : 0,
                        'conversion_by_value' => $totalValue > 0 ? round(($paidValue / $totalValue) * 100, 2) : 0,
                    ],
                    'by_unit' => array_map(static function (array $row): array {
                        $total = (int) ($row['total_sales'] ?? 0);
                        $paid = (int) ($row['paid_sales'] ?? 0);
                        $totalValueRow = (float) ($row['total_value'] ?? 0);
                        $paidValueRow = (float) ($row['paid_value'] ?? 0);

                        return [
                            'unit_id' => (int) ($row['unit_id'] ?? 0),
                            'unit_name' => (string) ($row['unit_name'] ?? ''),
                            'total_sales' => $total,
                            'total_value' => round($totalValueRow, 2),
                            'paid_sales' => $paid,
                            'paid_value' => round($paidValueRow, 2),
                            'links_generated' => (int) ($row['links_generated'] ?? 0),
                            'conversion_by_count' => $total > 0 ? round(($paid / $total) * 100, 2) : 0,
                            'conversion_by_value' => $totalValueRow > 0 ? round(($paidValueRow / $totalValueRow) * 100, 2) : 0,
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
            'CREATE TABLE IF NOT EXISTS plan_sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                unit_id INT NOT NULL,
                cpf VARCHAR(14) NOT NULL,
                customer_name VARCHAR(255) NOT NULL,
                phone VARCHAR(40) NULL,
                email VARCHAR(255) NULL,
                plan_name VARCHAR(255) NOT NULL,
                plan_value DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT "PENDING",
                payment_link TEXT NULL,
                evo_payload_json JSON NULL,
                evo_response_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_plan_sales_dates (created_at),
                INDEX idx_plan_sales_client (client_id),
                INDEX idx_plan_sales_unit (unit_id),
                INDEX idx_plan_sales_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
