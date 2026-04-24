<?php
declare(strict_types=1);

// Segurança base: não expor detalhes de runtime/caminhos internos.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');
error_reporting(E_ALL);

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

/**
 * Require seguro para evitar include de caminhos inválidos.
 */
function safeRequire(string $baseDir, string $relativeFile): mixed
{
    $base = realpath($baseDir);
    if ($base === false) {
        throw new RuntimeException('Base de aplicação indisponível.');
    }

    $fullPath = $base . DIRECTORY_SEPARATOR . ltrim($relativeFile, '/\\');
    $realPath = realpath($fullPath);

    if ($realPath === false || !str_starts_with($realPath, $base . DIRECTORY_SEPARATOR) || !is_readable($realPath)) {
        throw new RuntimeException('Arquivo de aplicação inválido.');
    }

    return require $realPath;
}

set_exception_handler(static function (Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $privateApiDir = __DIR__ . '/../../api-evokore';
    define('EVOKORE_INTERNAL_BOOT', true);

    $app = safeRequire($privateApiDir, 'bootstrap.php');
    $sessionName = trim((string) ($app['env']['ADMIN_SESSION_NAME'] ?? 'EVOKORESESSID'));
    if ($sessionName === '') {
        $sessionName = 'EVOKORESESSID';
    }
    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $router = safeRequire($privateApiDir, 'router.php');

    if (!is_callable($router)) {
        throw new RuntimeException('Router inválido.');
    }

    $router($app);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);
}
