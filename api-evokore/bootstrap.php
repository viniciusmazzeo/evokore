<?php
declare(strict_types=1);

// Impede execução direta via web/CLI fora do front controller.
if (!defined('EVOKORE_INTERNAL_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Autoload simples (PSR-4 básico): namespace EvoKore\\ -> /src
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'EvoKore\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

/**
 * Loader simples de .env sem dependências.
 */
function loadEnvFile(string $filePath): array
{
    $env = [];

    if (!is_readable($filePath)) {
        return $env;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $env;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($value !== '' && (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    return $env;
}

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function envInt(string $key, int $default): int
{
    $value = env($key);
    if ($value === null) {
        return $default;
    }

    $intValue = filter_var($value, FILTER_VALIDATE_INT);
    if ($intValue === false || $intValue < 0) {
        return $default;
    }

    return $intValue;
}

function envBool(string $key, bool $default = false): bool
{
    $value = env($key);
    if ($value === null) {
        return $default;
    }

    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

$envValues = loadEnvFile(__DIR__ . '/.env');

/**
 * Inicialização da conexão com banco via PDO.
 * Espera variáveis: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
 */
$db = null;

$dbHost = env('DB_HOST');
$dbName = env('DB_NAME');
$dbUser = env('DB_USER');
$dbPass = env('DB_PASS', '');
$dbPort = env('DB_PORT', '3306');
$dbCharset = env('DB_CHARSET', 'utf8mb4');

if ($dbHost !== null && $dbName !== null && $dbUser !== null) {
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);

        $db = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        // Webhook não deve cair se o banco estiver indisponível.
        $db = null;
        error_log('EvoKore DB bootstrap warning: ' . $e->getMessage());
    }
}

return [
    'env' => $envValues,
    'db' => $db,
    'config' => [
        'webhook' => [
            'token' => env('WEBHOOK_TOKEN'),
            'debug_auth' => envBool('WEBHOOK_DEBUG_AUTH', false),
            'max_body_bytes' => envInt('WEBHOOK_MAX_BODY_BYTES', 1048576),
            'rate_limit' => [
                'max_requests' => envInt('WEBHOOK_RATE_LIMIT_MAX', 60),
                'window_seconds' => envInt('WEBHOOK_RATE_LIMIT_WINDOW', 60),
            ],
        ],
    ],
    'paths' => [
        'base' => __DIR__,
        'storage' => __DIR__ . '/storage',
        'logs' => __DIR__ . '/storage/logs',
        'rate_limit' => __DIR__ . '/storage/ratelimit',
    ],
];
