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
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    // Compatibilidade com Apache/XAMPP quando o app roda em subdiretório
    // Ex.: /evokore/public_html/evokore/admin/clients -> /admin/clients
    if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath));
        if ($path === '') {
            $path = '/';
        }
    }

    header('Content-Type: application/json; charset=utf-8');

    if ($path === '/admin/auth/login') {
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $controller = new EvoKore\Controllers\AdminAuthController($app);
        $controller->login();
        return;
    }

    if ($path === '/admin/auth/me') {
        if ($method !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $controller = new EvoKore\Controllers\AdminAuthController($app);
        $controller->me();
        return;
    }

    if ($path === '/admin/auth/logout') {
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $controller = new EvoKore\Controllers\AdminAuthController($app);
        $controller->logout();
        return;
    }

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

    if ($method === 'GET' && $path === '/admin/clients') {
        $controller = new EvoKore\Controllers\AdminClientsController($app);
        $controller->handle();
        return;
    }

    if ($method === 'GET' && $path === '/admin/units') {
        $controller = new EvoKore\Controllers\AdminUnitsController($app);
        $controller->handle();
        return;
    }

    if ($method === 'GET' && $path === '/admin/dashboard') {
        $controller = new EvoKore\Controllers\AdminDashboardController($app);
        $controller->handle();
        return;
    }

    if ($method === 'GET' && $path === '/admin/dashboard/plan-sales') {
        $controller = new EvoKore\Controllers\AdminPlanSalesDashboardController($app);
        $controller->handle();
        return;
    }

    if ($method === 'GET' && $path === '/admin/dashboard/trials') {
        $controller = new EvoKore\Controllers\AdminTrialDashboardController($app);
        $controller->handle();
        return;
    }

    if (str_starts_with($path, '/admin/')) {
        $controller = new EvoKore\Controllers\AdminManagementController($app);
        $controller->handle($method, $path);
        return;
    }

    if ($method === 'POST' && $path === '/n8n/sales/plan') {
        $controller = new EvoKore\Controllers\N8nPlanSaleController($app);
        $controller->handle();
        return;
    }

    if ($method === 'POST' && $path === '/n8n/sales/trial') {
        $controller = new EvoKore\Controllers\N8nTrialClassController($app);
        $controller->handle();
        return;
    }

    if ($method === 'POST' && $path === '/n8n/sales/plan/status') {
        $controller = new EvoKore\Controllers\N8nPlanSaleStatusController($app);
        $controller->handle();
        return;
    }

    if ($method === 'POST' && $path === '/n8n/sales/trial/status') {
        $controller = new EvoKore\Controllers\N8nTrialStatusController($app);
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

    if (
        $path === '/n8n/sales/plan' ||
        $path === '/n8n/sales/trial' ||
        $path === '/n8n/sales/plan/status' ||
        $path === '/n8n/sales/trial/status'
    ) {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not Found'], JSON_UNESCAPED_UNICODE);
};
