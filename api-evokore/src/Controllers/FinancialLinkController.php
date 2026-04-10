<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use EvoKore\Services\FinancialStatusService;

final class FinancialLinkController
{
    /**
     * @var array<string,mixed>
     */
    private array $app;
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

    public function handle(): void
    {
        $expectedToken = (string) ($this->app['env']['FINANCIAL_ENDPOINT_TOKEN'] ?? '');
        if ($expectedToken === '') {
            $this->json(503, ['error' => 'Service unavailable']);
            return;
        }

        $provided = $this->extractAccessToken();
        if ($provided === '' || !hash_equals($expectedToken, $provided)) {
            $this->json(401, ['error' => 'Unauthorized']);
            return;
        }

        $memberId = $this->extractMemberId();
        $cpf = $this->extractCpf();
        if ($memberId === null && $cpf === '') {
            $this->json(400, ['error' => 'memberId or CPF is required']);
            return;
        }

        $service = new FinancialStatusService([
            'base_url' => (string) ($this->app['env']['EVO_BASE_URL'] ?? ''),
            'dns' => (string) ($this->app['env']['EVO_DNS'] ?? ''),
            'dns_header_name' => (string) ($this->app['env']['EVO_DNS_HEADER_NAME'] ?? 'DNS'),
            'token' => (string) ($this->app['env']['EVO_TOKEN'] ?? ''),
            'auth_mode' => (string) ($this->app['env']['EVO_AUTH_MODE'] ?? 'basic'),
            'timeout_seconds' => (int) ($this->app['env']['EVO_TIMEOUT_SECONDS'] ?? 10),
            'max_retries' => (int) ($this->app['env']['EVO_MAX_RETRIES'] ?? 2),
            'log_file' => (string) ($this->app['paths']['base'] ?? __DIR__ . '/../../') . '/logs/financial.log',
        ]);

        if ($memberId !== null) {
            $result = $service->getPaymentLinkByMemberId($memberId, $cpf !== '' ? $cpf : null);
        } else {
            $result = $service->getPaymentLinkByCpf($cpf);
        }
        $this->json(200, $result);
    }

    private function extractMemberId(): ?int
    {
        $fromQuery = (string) ($_GET['memberId'] ?? $_GET['idMember'] ?? '');
        if ($fromQuery !== '') {
            $digits = preg_replace('/\D+/', '', $fromQuery) ?? '';
            if ($digits !== '' && (int) $digits > 0) {
                return (int) $digits;
            }
        }

        $decoded = $this->getJsonBody();
        if ($decoded === []) {
            return null;
        }

        $value = (string) ($decoded['memberId'] ?? $decoded['idMember'] ?? '');
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '' || (int) $digits <= 0) {
            return null;
        }

        return (int) $digits;
    }

    private function extractCpf(): string
    {
        $queryCpf = (string) ($_GET['cpf'] ?? '');
        if ($queryCpf !== '') {
            return $queryCpf;
        }

        $decoded = $this->getJsonBody();
        if ($decoded === []) {
            return '';
        }

        return (string) ($decoded['cpf'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function getJsonBody(): array
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
        $this->jsonBody = is_array($decoded) ? $decoded : [];
        return $this->jsonBody;
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
     * @param array<string,mixed> $body
     */
    private function json(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
