<?php
declare(strict_types=1);

// Impede execução direta via web/CLI fora do front controller.
if (!defined('EVOKORE_INTERNAL_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

return static function (array $app): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    // Permite executar a API em raiz (/) ou em subdiretório (ex.: /evokore/public_html/evokore).
    if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath));
        $path = $path === '' ? '/' : $path;
    }

    header('Content-Type: application/json; charset=utf-8');

    if ($method === 'POST' && $path === '/webhook/evo') {
        $controller = new EvoKore\Controllers\WebhookController($app);
        $controller->handle();
        return;
    }

    if (($method === 'POST' || $method === 'GET') && $path === '/financial/status') {
        $controller = new EvoKore\Controllers\FinancialStatusController($app);
        $controller->handle();
        return;
    }

    if (($method === 'POST' || $method === 'GET') && $path === '/financial/link') {
        $controller = new EvoKore\Controllers\FinancialLinkController($app);
        $controller->handle();
        return;
    }

    if ($path === '/webhook/evo') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($path === '/financial/status') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($path === '/financial/link') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not Found'], JSON_UNESCAPED_UNICODE);
};
