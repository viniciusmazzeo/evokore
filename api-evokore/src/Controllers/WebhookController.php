<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use EvoKore\Security\RateLimiter;
use EvoKore\Support\RequestLogger;
use Throwable;

final class WebhookController
{
    /**
     * @var array<string,mixed>
     */
    private array $app;

    /**
     * @param array<string,mixed> $app
     */
    public function __construct(array $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $requestId = bin2hex(random_bytes(8));
        $ip = $this->resolveClientIp();
        $headers = $this->getAllHeadersSafe();
        $route = '/webhook/evo';
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'POST');
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false) {
            $this->log($requestId, $ip, $method, $route, $headers, '', null, 'invalid_body', 400);
            $this->json(400, ['error' => 'Invalid request body']);
            return;
        }

        $maxBodyBytes = (int) ($this->app['config']['webhook']['max_body_bytes'] ?? 1048576);
        if (strlen($rawBody) > $maxBodyBytes) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'payload_too_large', 413);
            $this->json(413, ['error' => 'Payload too large']);
            return;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($contentType !== '' && strpos($contentType, 'application/json') === false) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'invalid_content_type', 415);
            $this->json(415, ['error' => 'Unsupported Media Type']);
            return;
        }

        $limiter = new RateLimiter(
            (string) ($this->app['paths']['rate_limit'] ?? __DIR__ . '/../../storage/ratelimit'),
            (int) ($this->app['config']['webhook']['rate_limit']['max_requests'] ?? 60),
            (int) ($this->app['config']['webhook']['rate_limit']['window_seconds'] ?? 60)
        );

        $rateKey = $route . '|' . $ip;
        $rate = $limiter->hit($rateKey);
        if (!$rate['allowed']) {
            header('Retry-After: ' . $rate['retry_after']);
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'rate_limited', 429);
            $this->json(429, ['error' => 'Too Many Requests']);
            return;
        }

        header('X-RateLimit-Remaining: ' . $rate['remaining']);

        $configuredToken = (string) ($this->app['config']['webhook']['token'] ?? '');
        $debugAuth = (bool) ($this->app['config']['webhook']['debug_auth'] ?? false);
        $tokenData = $this->extractToken();
        $requestToken = $tokenData['token'];
        if ($configuredToken === '' || $requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'invalid_token', 401);
            $response = ['error' => 'Unauthorized'];
            if ($debugAuth) {
                $response['auth_debug'] = [
                    'source' => $tokenData['source'],
                    'received_token_len' => strlen($requestToken),
                    'configured_token_len' => strlen($configuredToken),
                    'received_token_hash8' => $requestToken !== '' ? substr(hash('sha256', $requestToken), 0, 8) : '',
                    'configured_token_hash8' => $configuredToken !== '' ? substr(hash('sha256', $configuredToken), 0, 8) : '',
                ];
            }
            $this->json(401, $response);
            return;
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'invalid_json', 400);
            $this->json(400, ['error' => 'Invalid JSON']);
            return;
        }

        if (!is_array($decoded)) {
            $this->log($requestId, $ip, $method, $route, $headers, $rawBody, null, 'invalid_payload_type', 400);
            $this->json(400, ['error' => 'JSON object expected']);
            return;
        }

        $sanitized = $this->sanitize($decoded);
        $this->log($requestId, $ip, $method, $route, $headers, $rawBody, $sanitized, 'accepted', 200);

        $this->json(200, [
            'ok' => true,
            'request_id' => $requestId,
            'message' => 'Webhook received',
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
                continue;
            }

            if (is_string($value)) {
                $clean = trim($value);
                $clean = preg_replace('/[[:cntrl:]]/u', '', $clean) ?? '';
                $payload[$key] = $clean;
            }
        }

        return $payload;
    }

    /**
     * @return array{token:string,source:string}
     */
    private function extractToken(): array
    {
        $headers = $this->getAllHeadersSafe();

        $xWebhookToken = $headers['x-webhook-token'] ?? '';
        if (is_string($xWebhookToken) && $xWebhookToken !== '') {
            return ['token' => trim($xWebhookToken), 'source' => 'header:x-webhook-token'];
        }

        $serverToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? $_SERVER['REDIRECT_HTTP_X_WEBHOOK_TOKEN'] ?? '';
        if (is_string($serverToken) && $serverToken !== '') {
            return ['token' => trim($serverToken), 'source' => 'server:http_x_webhook_token'];
        }

        $auth = $headers['authorization'] ?? '';
        if (!is_string($auth) || $auth === '') {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if (!is_string($auth) || $auth === '') {
            return ['token' => '', 'source' => 'none'];
        }

        if (stripos($auth, 'Bearer ') === 0) {
            return ['token' => trim(substr($auth, 7)), 'source' => 'header:authorization-bearer'];
        }

        return ['token' => '', 'source' => 'authorization-without-bearer'];
    }

    /**
     * @return array<string,string>
     */
    private function getAllHeadersSafe(): array
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!is_array($headers)) {
            $headers = [];
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
        }

        return $normalized;
    }

    private function resolveClientIp(): string
    {
        $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (is_string($cfIp) && $cfIp !== '') {
            return $cfIp;
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($remote) && $remote !== '' ? $remote : '0.0.0.0';
    }

    /**
     * @param array<string,mixed>|null $sanitizedPayload
     */
    private function log(
        string $requestId,
        string $ip,
        string $method,
        string $route,
        array $headers,
        string $rawBody,
        ?array $sanitizedPayload,
        string $status,
        int $httpStatus
    ): void {
        $redactedHeaders = $headers;
        if (isset($redactedHeaders['authorization'])) {
            $redactedHeaders['authorization'] = '[REDACTED]';
        }
        if (isset($redactedHeaders['x-webhook-token'])) {
            $redactedHeaders['x-webhook-token'] = '[REDACTED]';
        }

        $logger = new RequestLogger((string) ($this->app['paths']['logs'] ?? __DIR__ . '/../../storage/logs') . '/webhook.log');
        $logger->write([
            'timestamp' => gmdate('c'),
            'request_id' => $requestId,
            'ip' => $ip,
            'method' => $method,
            'route' => $route,
            'status' => $status,
            'http_status' => $httpStatus,
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'headers' => $redactedHeaders,
            'raw_body' => $rawBody,
            'sanitized_payload' => $sanitizedPayload,
        ]);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(int $statusCode, array $body): void
    {
        http_response_code($statusCode);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
