<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use PDO;
use RuntimeException;
use Throwable;

final class N8nPlanSaleStatusController extends N8nFlowController
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

            $this->ensureTable($db);
            $sale = $this->resolveSale($db, (int) $context['unit_id'], $normalized);
            if ($sale === null) {
                throw new RuntimeException('Venda não encontrada para a unidade informada.');
            }

            $stmt = $db->prepare(
                'UPDATE plan_sales
                 SET status = :status,
                     paid_value = :paid_value,
                     paid_at = :paid_at,
                     payment_link = COALESCE(:payment_link, payment_link),
                     status_note = :status_note,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => $normalized['status'],
                ':paid_value' => $normalized['paid_value'],
                ':paid_at' => $normalized['paid_at'],
                ':payment_link' => $normalized['payment_link'],
                ':status_note' => $normalized['status_note'],
                ':id' => (int) $sale['id'],
            ]);

            $this->json(200, [
                'data' => [
                    'sale_id' => (int) $sale['id'],
                    'unit_id' => (int) $context['unit_id'],
                    'status' => $normalized['status'],
                    'paid_value' => $normalized['paid_value'],
                    'paid_at' => $normalized['paid_at'],
                    'message' => 'Status da venda atualizado com sucesso.',
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
        $saleId = isset($payload['sale_id']) ? (int) $payload['sale_id'] : null;
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));
        $paidValue = isset($payload['paid_value']) ? (float) $payload['paid_value'] : null;
        $paidAt = trim((string) ($payload['paid_at'] ?? ''));
        $paymentLink = trim((string) ($payload['payment_link'] ?? ''));
        $statusNote = trim((string) ($payload['status_note'] ?? ''));
        $cpf = preg_replace('/\D+/', '', (string) ($payload['cpf'] ?? '')) ?? '';
        $planName = trim((string) ($payload['plan_name'] ?? ''));

        if ($status === '') {
            throw new RuntimeException('status é obrigatório.');
        }

        $allowed = ['PENDING', 'PAID', 'CONFIRMED', 'CANCELED', 'CANCELLED', 'FAILED'];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('status inválido para venda.');
        }

        if ($saleId === null && ($cpf === '' || $planName === '')) {
            throw new RuntimeException('Informe sale_id ou cpf + plan_name.');
        }

        return [
            'sale_id' => $saleId,
            'status' => $status,
            'paid_value' => $paidValue,
            'paid_at' => $paidAt !== '' ? $paidAt : null,
            'payment_link' => $paymentLink !== '' ? $paymentLink : null,
            'status_note' => $statusNote !== '' ? $statusNote : null,
            'cpf' => $cpf,
            'plan_name' => $planName,
        ];
    }

    /**
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>|null
     */
    private function resolveSale(PDO $db, int $unitId, array $normalized): ?array
    {
        if ($normalized['sale_id'] !== null) {
            $stmt = $db->prepare('SELECT id FROM plan_sales WHERE id = :id AND unit_id = :unit_id LIMIT 1');
            $stmt->execute([
                ':id' => (int) $normalized['sale_id'],
                ':unit_id' => $unitId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        $stmt = $db->prepare(
            'SELECT id
             FROM plan_sales
             WHERE unit_id = :unit_id AND cpf = :cpf AND plan_name = :plan_name
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':unit_id' => $unitId,
            ':cpf' => $normalized['cpf'],
            ':plan_name' => $normalized['plan_name'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function ensureTable(PDO $db): void
    {
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS paid_value DECIMAL(12,2) NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS status_note VARCHAR(255) NULL');
    }
}

