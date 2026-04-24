<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use EvoKore\Support\UnitContextResolver;
use PDO;
use RuntimeException;
use Throwable;

abstract class N8nFlowController
{
    /**
     * @var array<string,mixed>
     */
    protected array $app;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $jsonBody = null;

    /**
     * @param array<string,mixed> $app
     */
    public function __construct(array $app)
    {
        $this->app = $app;
    }

    protected function db(): ?PDO
    {
        $db = $this->app['db'] ?? null;
        return $db instanceof PDO ? $db : null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function requireUnitContext(): array
    {
        $db = $this->db();
        if ($db === null) {
            throw new RuntimeException('Database unavailable.');
        }

        $resolver = new UnitContextResolver($db, (array) ($this->app['env'] ?? []));
        $token = $this->extractAccessToken();
        return $resolver->resolveByToken($token);
    }

    /**
     * @return array<string,mixed>
     */
    protected function getJsonBody(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON body.');
        }

        $this->jsonBody = $decoded;
        return $this->jsonBody;
    }

    protected function extractAccessToken(): string
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
     * @param array<string,mixed> $body
     */
    protected function json(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,mixed> $entry
     */
    protected function writeLog(string $file, array $entry): void
    {
        try {
            $line = json_encode([
                'timestamp' => gmdate('c'),
                'entry' => $entry,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($line === false) {
                return;
            }

            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }

            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // noop
        }
    }
}
