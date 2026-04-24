<?php
declare(strict_types=1);

namespace EvoKore\Security;

final class AdminAuth
{
    /**
     * @param array<string,mixed> $app
     */
    public static function isAuthenticated(array $app): bool
    {
        if (self::isSessionAuthenticated()) {
            return true;
        }

        if (!self::isTokenAuthEnabled($app)) {
            return false;
        }

        $expected = self::resolveExpectedToken($app);
        if ($expected === '') {
            return false;
        }

        $provided = self::extractAccessToken();
        return $provided !== '' && hash_equals($expected, $provided);
    }

    public static function isSessionAuthenticated(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $admin = $_SESSION['admin_user'] ?? null;
        if (!is_array($admin)) {
            return false;
        }

        return isset($admin['id'], $admin['username']);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function sessionUser(): ?array
    {
        if (!self::isSessionAuthenticated()) {
            return null;
        }

        $admin = $_SESSION['admin_user'];
        if (!is_array($admin)) {
            return null;
        }

        return $admin;
    }

    public static function extractAccessToken(): string
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
     * @param array<string,mixed> $app
     */
    private static function isTokenAuthEnabled(array $app): bool
    {
        $raw = strtolower(trim((string) ($app['env']['ADMIN_ALLOW_TOKEN_AUTH'] ?? '1')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string,mixed> $app
     */
    private static function resolveExpectedToken(array $app): string
    {
        $admin = trim((string) ($app['env']['ADMIN_API_TOKEN'] ?? ''));
        if ($admin !== '') {
            return $admin;
        }

        return trim((string) ($app['env']['FINANCIAL_ENDPOINT_TOKEN'] ?? ''));
    }
}

