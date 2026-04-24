<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use DateInterval;
use DateTimeImmutable;
use EvoKore\Security\AdminAuth;
use PDO;

abstract class AdminController
{
    /**
     * @var array<string,mixed>
     */
    protected array $app;

    /**
     * @param array<string,mixed> $app
     */
    public function __construct(array $app)
    {
        $this->app = $app;
    }

    protected function requireAdminToken(): bool
    {
        if (!AdminAuth::isAuthenticated($this->app)) {
            $this->json(401, ['error' => 'Unauthorized']);
            return false;
        }

        return true;
    }

    protected function db(): ?PDO
    {
        $db = $this->app['db'] ?? null;
        return $db instanceof PDO ? $db : null;
    }

    /**
     * @param array<string,mixed> $body
     */
    protected function json(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{start:DateTimeImmutable,end:DateTimeImmutable}
     */
    protected function parseDateRange(): array
    {
        $startRaw = trim((string) ($_GET['start_date'] ?? ''));
        $endRaw = trim((string) ($_GET['end_date'] ?? ''));

        $today = new DateTimeImmutable('today');
        $defaultStart = $today->modify('first day of this month');
        $defaultEnd = $today;

        $start = $this->parseDateOnly($startRaw) ?? $defaultStart;
        $end = $this->parseDateOnly($endRaw) ?? $defaultEnd;

        if ($end < $start) {
            $end = $start;
        }

        return ['start' => $start, 'end' => $end];
    }

    protected function parsePositiveInt(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<string,string>
     */
    protected function getTableColumns(string $tableName): array
    {
        $db = $this->db();
        if ($db === null) {
            return [];
        }

        $dbName = (string) ($this->app['env']['DB_NAME'] ?? '');
        if ($dbName === '') {
            return [];
        }

        $stmt = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table'
        );
        $stmt->execute([
            ':schema' => $dbName,
            ':table' => $tableName,
        ]);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            if (is_string($column) && $column !== '') {
                $columns[$column] = $column;
            }
        }

        return $columns;
    }

    protected function pickColumn(array $available, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($available[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    protected function endDateExclusive(DateTimeImmutable $end): DateTimeImmutable
    {
        return $end->add(new DateInterval('P1D'));
    }

    private function parseDateOnly(string $raw): ?DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if (!$date instanceof DateTimeImmutable) {
            return null;
        }

        return $date->setTime(0, 0, 0);
    }

}
