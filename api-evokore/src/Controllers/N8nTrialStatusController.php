<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use PDO;
use RuntimeException;
use Throwable;

final class N8nTrialStatusController extends N8nFlowController
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

            $trial = $this->resolveTrial($db, (int) $context['unit_id'], $normalized);
            if ($trial === null) {
                throw new RuntimeException('Aula experimental não encontrada para a unidade informada.');
            }

            $stmt = $db->prepare(
                'UPDATE trial_classes
                 SET status = :status,
                     trial_date = COALESCE(:trial_date, trial_date),
                     trial_time = COALESCE(:trial_time, trial_time),
                     status_note = :status_note,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => $normalized['status'],
                ':trial_date' => $normalized['trial_date'],
                ':trial_time' => $normalized['trial_time'],
                ':status_note' => $normalized['status_note'],
                ':id' => (int) $trial['id'],
            ]);

            $this->json(200, [
                'data' => [
                    'trial_id' => (int) $trial['id'],
                    'unit_id' => (int) $context['unit_id'],
                    'status' => $normalized['status'],
                    'message' => 'Status da aula experimental atualizado com sucesso.',
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
        $trialId = isset($payload['trial_id']) ? (int) $payload['trial_id'] : null;
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));
        $trialDate = trim((string) ($payload['trial_date'] ?? ''));
        $trialTime = trim((string) ($payload['trial_time'] ?? ''));
        $statusNote = trim((string) ($payload['status_note'] ?? ''));
        $cpf = preg_replace('/\D+/', '', (string) ($payload['cpf'] ?? '')) ?? '';
        $preferredDate = trim((string) ($payload['preferred_date'] ?? ''));

        if ($status === '') {
            throw new RuntimeException('status é obrigatório.');
        }

        $allowed = ['SCHEDULED', 'CONFIRMED', 'DONE', 'COMPLETED', 'CANCELED', 'CANCELLED', 'NO_SHOW'];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('status inválido para aula experimental.');
        }

        if ($trialId === null && ($cpf === '' || $preferredDate === '')) {
            throw new RuntimeException('Informe trial_id ou cpf + preferred_date.');
        }

        return [
            'trial_id' => $trialId,
            'status' => $status,
            'trial_date' => $trialDate !== '' ? $trialDate : null,
            'trial_time' => $trialTime !== '' ? $trialTime : null,
            'status_note' => $statusNote !== '' ? $statusNote : null,
            'cpf' => $cpf,
            'preferred_date' => $preferredDate,
        ];
    }

    /**
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>|null
     */
    private function resolveTrial(PDO $db, int $unitId, array $normalized): ?array
    {
        if ($normalized['trial_id'] !== null) {
            $stmt = $db->prepare('SELECT id FROM trial_classes WHERE id = :id AND unit_id = :unit_id LIMIT 1');
            $stmt->execute([
                ':id' => (int) $normalized['trial_id'],
                ':unit_id' => $unitId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        $stmt = $db->prepare(
            'SELECT id
             FROM trial_classes
             WHERE unit_id = :unit_id AND cpf = :cpf AND preferred_date = :preferred_date
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':unit_id' => $unitId,
            ':cpf' => $normalized['cpf'],
            ':preferred_date' => $normalized['preferred_date'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

