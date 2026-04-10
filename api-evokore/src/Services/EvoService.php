<?php
declare(strict_types=1);

namespace EvoKore\Services;

use RuntimeException;
use Throwable;

final class EvoService
{
    private string $baseUrl;
    private string $dns;
    private string $token;
    private string $apiKey;
    private string $authMode;
    private string $dnsHeaderName;
    private int $timeoutSeconds;
    private int $maxRetries;
    private string $logFile;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? $this->env('EVO_BASE_URL', '')), '/');
        $this->dns = (string) ($config['dns'] ?? $this->env('EVO_DNS', ''));
        $this->token = (string) ($config['token'] ?? $this->env('EVO_TOKEN', ''));
        $this->apiKey = (string) ($config['api_key'] ?? $this->env('EVO_APIKEY', ''));
        $this->authMode = strtolower((string) ($config['auth_mode'] ?? $this->env('EVO_AUTH_MODE', 'bearer')));
        $this->dnsHeaderName = (string) ($config['dns_header_name'] ?? $this->env('EVO_DNS_HEADER_NAME', 'DNS'));
        $this->timeoutSeconds = (int) ($config['timeout_seconds'] ?? $this->envInt('EVO_TIMEOUT_SECONDS', 10));
        $this->maxRetries = (int) ($config['max_retries'] ?? $this->envInt('EVO_MAX_RETRIES', 2));
        $this->logFile = (string) ($config['log_file'] ?? dirname(__DIR__, 2) . '/logs/evo.log');

        if ($this->baseUrl === '') {
            throw new RuntimeException('EVO_BASE_URL nao configurada.');
        }
        if ($this->token === '') {
            throw new RuntimeException('EVO_TOKEN nao configurado.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getStudent(string $externalId): array
    {
        $cpf = $this->sanitizeCpf($externalId);
        return $this->request('GET', '/api/v1/members/basic', [
            'document' => $cpf,
            'take' => '1',
            'skip' => '0',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function getPayments(string $externalId): array
    {
        $id = $this->resolveMemberIdByCpf($externalId);
        return $this->request('GET', '/api/v1/receivables', [
            'memberId' => $id,
            'take' => '50',
            'skip' => '0',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function getCheckins(string $externalId, string $startDate, string $endDate): array
    {
        $id = $this->resolveMemberIdByCpf($externalId);
        $start = $this->sanitizeDate($startDate, 'startDate');
        $end = $this->sanitizeDate($endDate, 'endDate');

        return $this->request('GET', '/api/v1/entries', [
            'idMember' => $id,
            'registerDateStart' => $start . 'T00:00:00Z',
            'registerDateEnd' => $end . 'T23:59:59Z',
            'take' => '50',
            'skip' => '0',
        ]);
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        $requestHeaders = $this->buildHeaders();
        $attempt = 0;
        $lastError = '';

        while ($attempt <= $this->maxRetries) {
            $attempt++;
            $ch = curl_init($url);

            if ($ch === false) {
                throw new RuntimeException('Falha ao inicializar cURL.');
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_HEADER => false,
            ]);

            $rawResponse = curl_exec($ch);
            $curlErrNo = curl_errno($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrNo !== 0) {
                $lastError = 'cURL error ' . $curlErrNo . ': ' . $curlError;
                $this->writeLog([
                    'level' => 'error',
                    'type' => 'transport_error',
                    'attempt' => $attempt,
                    'method' => $method,
                    'url' => $url,
                    'request_headers' => $this->redactHeadersForLog($requestHeaders),
                    'error' => $lastError,
                ]);

                if ($attempt <= $this->maxRetries) {
                    usleep(200000);
                    continue;
                }

                throw new RuntimeException($lastError);
            }

            $responseBody = is_string($rawResponse) ? $rawResponse : '';
            $this->writeLog([
                'level' => 'info',
                'type' => 'evo_request',
                'attempt' => $attempt,
                'method' => $method,
                'url' => $url,
                'request_headers' => $this->redactHeadersForLog($requestHeaders),
                'http_code' => $httpCode,
                'response' => $this->safeLogResponse($responseBody),
            ]);

            if ($httpCode >= 500 && $attempt <= $this->maxRetries) {
                usleep(200000);
                continue;
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $message = $this->extractErrorMessage($responseBody);
                throw new RuntimeException('EVO HTTP ' . $httpCode . ': ' . $message);
            }

            $decoded = json_decode($responseBody, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Resposta EVO invalida (JSON esperado).');
            }

            return $decoded;
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Falha inesperada ao chamar EVO.');
    }

    /**
     * @param array<string,string> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $normalizedPath = '/' . ltrim($path, '/');
        $url = $this->baseUrl . $normalizedPath;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @return string[]
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept: application/json',
        ];

        if ($this->authMode === 'basic') {
            // Padrão EVO validado via curl: -u "DNS:TOKEN"
            $basicUser = $this->dns !== '' ? $this->dns : 'default';
            $basicPass = rtrim($this->token, ':');
            $headers[] = 'Authorization: Basic ' . base64_encode($basicUser . ':' . $basicPass);
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if ($this->dns !== '') {
            $headers[] = $this->dnsHeaderName . ': ' . $this->dns;
        }
        if ($this->apiKey !== '') {
            $headers[] = 'ApiKey: ' . $this->apiKey;
        }

        return $headers;
    }

    private function sanitizeExternalId(string $externalId): string
    {
        $value = trim($externalId);
        $value = preg_replace('/[[:cntrl:]]/u', '', $value) ?? '';

        if ($value === '') {
            throw new RuntimeException('externalId obrigatorio.');
        }
        if (strlen($value) > 128) {
            throw new RuntimeException('externalId invalido.');
        }

        return $value;
    }

    private function sanitizeCpf(string $cpf): string
    {
        $digits = preg_replace('/\D+/', '', $cpf) ?? '';
        if (strlen($digits) !== 11) {
            throw new RuntimeException('CPF invalido. Use 11 digitos.');
        }

        return $digits;
    }

    private function resolveMemberIdByCpf(string $cpf): string
    {
        $document = $this->sanitizeCpf($cpf);
        $member = $this->request('GET', '/api/v1/members/basic', [
            'document' => $document,
            'take' => '1',
            'skip' => '0',
        ]);

        $candidate = $this->extractIdMember($member);
        if ($candidate === '') {
            throw new RuntimeException('Nao foi possivel resolver idMember pelo CPF informado.');
        }

        return $candidate;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractIdMember(array $payload): string
    {
        if (isset($payload['idMember']) && is_scalar($payload['idMember'])) {
            return (string) $payload['idMember'];
        }
        if (isset($payload['idCliente']) && is_scalar($payload['idCliente'])) {
            return (string) $payload['idCliente'];
        }

        $items = $payload['items'] ?? $payload['data'] ?? $payload;
        if (is_array($items)) {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (isset($row['idMember']) && is_scalar($row['idMember'])) {
                    return (string) $row['idMember'];
                }
                if (isset($row['idCliente']) && is_scalar($row['idCliente'])) {
                    return (string) $row['idCliente'];
                }
            }
        }

        return '';
    }

    private function sanitizeDate(string $date, string $field): string
    {
        $value = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException($field . ' invalido. Use YYYY-MM-DD.');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new RuntimeException($field . ' invalido.');
        }

        return date('Y-m-d', $timestamp);
    }

    private function extractErrorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = $decoded['message'] ?? $decoded['error'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        $plain = trim($body);
        if ($plain === '') {
            return 'Erro HTTP sem corpo.';
        }

        return substr($plain, 0, 300);
    }

    /**
     * @param string[] $headers
     * @return string[]
     */
    private function redactHeadersForLog(array $headers): array
    {
        $safe = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Authorization:') === 0) {
                $safe[] = 'Authorization: [REDACTED]';
                continue;
            }
            $safe[] = $header;
        }

        return $safe;
    }

    /**
     * @return array<string,mixed>
     */
    private function safeLogResponse(string $responseBody): array
    {
        $decoded = json_decode($responseBody, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'raw' => substr($responseBody, 0, 2000),
        ];
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function writeLog(array $entry): void
    {
        try {
            $dir = dirname($this->logFile);
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return;
            }

            $line = json_encode([
                'timestamp' => gmdate('c'),
                'entry' => $entry,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($line === false) {
                return;
            }

            @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Erro de log nunca deve quebrar o fluxo principal.
        }
    }

    private function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    private function envInt(string $key, int $default): int
    {
        $raw = $this->env($key, (string) $default);
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) {
            return $default;
        }

        return $value;
    }
}
