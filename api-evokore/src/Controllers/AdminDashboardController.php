<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use PDO;
use Throwable;

final class AdminDashboardController extends AdminController
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
            $columns = $this->getTableColumns('debtor_events');
            if ($columns === []) {
                $this->json(200, $this->emptyDashboardPayload('Tabela debtor_events indisponível.'));
                return;
            }

            $eventTypeCol = $this->pickColumn($columns, ['event_type', 'event', 'type']);
            $amountCol = $this->pickColumn($columns, ['amount', 'debt_amount', 'value_amount', 'amount_total']);
            $cpfCol = $this->pickColumn($columns, ['cpf_hash', 'cpf', 'document']);
            $unitIdCol = $this->pickColumn($columns, ['unit_id']);
            $clientIdCol = $this->pickColumn($columns, ['client_id']);
            $occurredAtCol = $this->pickColumn($columns, ['happened_at', 'event_at', 'created_at']);

            if ($eventTypeCol === null || $amountCol === null || $occurredAtCol === null) {
                $this->json(200, $this->emptyDashboardPayload('Colunas mínimas de debtor_events não encontradas.'));
                return;
            }

            $range = $this->parseDateRange();
            $start = $range['start'];
            $end = $range['end'];
            $endExclusive = $this->endDateExclusive($end);

            $clientId = $this->parsePositiveInt((string) ($_GET['client_id'] ?? ''));
            $unitId = $this->parsePositiveInt((string) ($_GET['unit_id'] ?? ''));

            $where = ["e.`{$occurredAtCol}` >= :start_date", "e.`{$occurredAtCol}` < :end_date"];
            $params = [
                ':start_date' => $start->format('Y-m-d 00:00:00'),
                ':end_date' => $endExclusive->format('Y-m-d 00:00:00'),
            ];

            if ($clientId !== null && $clientIdCol !== null) {
                $where[] = "e.`{$clientIdCol}` = :client_id";
                $params[':client_id'] = $clientId;
            }

            if ($unitId !== null && $unitIdCol !== null) {
                $where[] = "e.`{$unitIdCol}` = :unit_id";
                $params[':unit_id'] = $unitId;
            }

            $whereSql = implode(' AND ', $where);
            $distinctField = $cpfCol !== null ? "e.`{$cpfCol}`" : "CAST(e.id AS CHAR)";

            $summarySql = "
                SELECT
                    COUNT(CASE WHEN e.`{$eventTypeCol}` = 'PAYMENT_LINK_SENT' THEN 1 END) AS links_generated,
                    COUNT(DISTINCT CASE WHEN e.`{$eventTypeCol}` = 'DELINQUENT_FOUND' THEN {$distinctField} END) AS delinquent_count,
                    COALESCE(SUM(CASE WHEN e.`{$eventTypeCol}` = 'DELINQUENT_FOUND' THEN e.`{$amountCol}` ELSE 0 END), 0) AS delinquent_amount,
                    COUNT(DISTINCT CASE WHEN e.`{$eventTypeCol}` = 'REGULARIZED' THEN {$distinctField} END) AS regularized_count,
                    COALESCE(SUM(CASE WHEN e.`{$eventTypeCol}` = 'REGULARIZED' THEN e.`{$amountCol}` ELSE 0 END), 0) AS regularized_amount
                FROM debtor_events e
                WHERE {$whereSql}
            ";

            $summaryStmt = $db->prepare($summarySql);
            $summaryStmt->execute($params);
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $linksGenerated = (int) ($summary['links_generated'] ?? 0);
            $delinquentCount = (int) ($summary['delinquent_count'] ?? 0);
            $regularizedCount = (int) ($summary['regularized_count'] ?? 0);
            $delinquentAmount = round((float) ($summary['delinquent_amount'] ?? 0), 2);
            $regularizedAmount = round((float) ($summary['regularized_amount'] ?? 0), 2);

            $conversionByLinks = $linksGenerated > 0 ? round(($regularizedCount / $linksGenerated) * 100, 2) : 0.0;
            $conversionByAmount = $delinquentAmount > 0 ? round(($regularizedAmount / $delinquentAmount) * 100, 2) : 0.0;

            $rows = $this->queryByUnitRows(
                $db,
                $eventTypeCol,
                $amountCol,
                $distinctField,
                $whereSql,
                $params,
                $unitIdCol
            );

            $this->json(200, [
                'data' => [
                    'summary' => [
                        'links_generated' => $linksGenerated,
                        'delinquent_count' => $delinquentCount,
                        'delinquent_amount' => $delinquentAmount,
                        'regularized_count' => $regularizedCount,
                        'regularized_amount' => $regularizedAmount,
                        'conversion_by_links' => $conversionByLinks,
                        'conversion_by_amount' => $conversionByAmount,
                    ],
                    'by_unit' => $rows,
                    'period' => [
                        'start_date' => $start->format('Y-m-d'),
                        'end_date' => $end->format('Y-m-d'),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            $this->json(500, ['error' => 'Internal Server Error', 'detail' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function queryByUnitRows(
        PDO $db,
        string $eventTypeCol,
        string $amountCol,
        string $distinctField,
        string $whereSql,
        array $params,
        ?string $unitIdCol
    ): array {
        if ($unitIdCol === null) {
            return [];
        }

        $sql = "
            SELECT
                e.`{$unitIdCol}` AS unit_id,
                COALESCE(u.unit_name, CONCAT('Unidade #', e.`{$unitIdCol}`)) AS unit_name,
                COUNT(CASE WHEN e.`{$eventTypeCol}` = 'PAYMENT_LINK_SENT' THEN 1 END) AS links_generated,
                COUNT(DISTINCT CASE WHEN e.`{$eventTypeCol}` = 'DELINQUENT_FOUND' THEN {$distinctField} END) AS delinquent_count,
                COALESCE(SUM(CASE WHEN e.`{$eventTypeCol}` = 'DELINQUENT_FOUND' THEN e.`{$amountCol}` ELSE 0 END), 0) AS delinquent_amount,
                COUNT(DISTINCT CASE WHEN e.`{$eventTypeCol}` = 'REGULARIZED' THEN {$distinctField} END) AS regularized_count,
                COALESCE(SUM(CASE WHEN e.`{$eventTypeCol}` = 'REGULARIZED' THEN e.`{$amountCol}` ELSE 0 END), 0) AS regularized_amount
            FROM debtor_events e
            LEFT JOIN units u ON u.id = e.`{$unitIdCol}`
            WHERE {$whereSql}
            GROUP BY e.`{$unitIdCol}`, u.unit_name
            ORDER BY regularized_amount DESC, regularized_count DESC
            LIMIT 20
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $links = (int) ($row['links_generated'] ?? 0);
            $regularized = (int) ($row['regularized_count'] ?? 0);

            return [
                'unit_id' => (int) ($row['unit_id'] ?? 0),
                'unit_name' => (string) ($row['unit_name'] ?? 'Sem nome'),
                'links_generated' => $links,
                'delinquent_count' => (int) ($row['delinquent_count'] ?? 0),
                'delinquent_amount' => round((float) ($row['delinquent_amount'] ?? 0), 2),
                'regularized_count' => $regularized,
                'regularized_amount' => round((float) ($row['regularized_amount'] ?? 0), 2),
                'conversion_by_links' => $links > 0 ? round(($regularized / $links) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyDashboardPayload(string $reason): array
    {
        return [
            'data' => [
                'summary' => [
                    'links_generated' => 0,
                    'delinquent_count' => 0,
                    'delinquent_amount' => 0,
                    'regularized_count' => 0,
                    'regularized_amount' => 0,
                    'conversion_by_links' => 0,
                    'conversion_by_amount' => 0,
                ],
                'by_unit' => [],
                'meta' => ['reason' => $reason],
            ],
        ];
    }
}

