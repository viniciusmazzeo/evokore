<?php
declare(strict_types=1);

use EvoKore\Services\FinancialStatusService;

ini_set('display_errors', '0');
error_reporting(E_ALL);
header_remove('X-Powered-By');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function requireTestAccess(array $env): void
{
    $userExpected = trim((string) ($env['TEST_EVO_USER'] ?? ''));
    $passExpected = trim((string) ($env['TEST_EVO_PASS'] ?? ''));

    if ($userExpected === '' || $passExpected === '') {
        return;
    }

    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

    if ($user === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && stripos((string) $_SERVER['HTTP_AUTHORIZATION'], 'Basic ') === 0) {
        $decoded = base64_decode(substr((string) $_SERVER['HTTP_AUTHORIZATION'], 6), true);
        if (is_string($decoded) && str_contains($decoded, ':')) {
            [$user, $pass] = explode(':', $decoded, 2);
        }
    }

    if (!hash_equals($userExpected, $user) || !hash_equals($passExpected, $pass)) {
        header('WWW-Authenticate: Basic realm="Evo Test"');
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
}

$privateApiDir = realpath(__DIR__ . '/../../api-evokore');
if ($privateApiDir === false) {
    jsonResponse(500, ['ok' => false, 'error' => 'Private API directory not found']);
}

define('EVOKORE_INTERNAL_BOOT', true);
$app = require $privateApiDir . '/bootstrap.php';
requireTestAccess((array) ($app['env'] ?? []));

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? $_POST : $_GET;
$cpfInput = trim((string) ($input['cpf'] ?? $input['externalId'] ?? ''));
$memberIdInput = trim((string) ($input['memberId'] ?? $input['idMember'] ?? ''));
$action = (string) ($input['action'] ?? 'financialStatus');
if ($action !== 'financialLink') {
    $action = 'financialStatus';
}
$asJson = ((string) ($input['format'] ?? 'html')) === 'json';

$result = null;
$error = null;
$responseMs = null;

if ($cpfInput !== '' || $memberIdInput !== '') {
    $service = new FinancialStatusService([
        'base_url' => (string) ($app['env']['EVO_BASE_URL'] ?? ''),
        'dns' => (string) ($app['env']['EVO_DNS'] ?? ''),
        'dns_header_name' => (string) ($app['env']['EVO_DNS_HEADER_NAME'] ?? 'DNS'),
        'token' => (string) ($app['env']['EVO_TOKEN'] ?? ''),
        'auth_mode' => (string) ($app['env']['EVO_AUTH_MODE'] ?? 'basic'),
        'timeout_seconds' => (int) ($app['env']['EVO_TIMEOUT_SECONDS'] ?? 10),
        'max_retries' => (int) ($app['env']['EVO_MAX_RETRIES'] ?? 2),
        'log_file' => $privateApiDir . '/logs/financial.log',
    ]);

    $start = microtime(true);
    try {
        if ($action === 'financialLink') {
            if ($memberIdInput !== '') {
                $raw = $service->getPaymentLinkByMemberId($memberIdInput, $cpfInput !== '' ? $cpfInput : null);
            } else {
                $raw = $service->getPaymentLinkByCpf($cpfInput);
            }
        } else {
            if ($memberIdInput !== '') {
                $raw = $service->checkByMemberId($memberIdInput, $cpfInput !== '' ? $cpfInput : null);
            } else {
                $raw = $service->checkByCpf($cpfInput);
            }
        }

        $result = [
            'cpf' => $raw['cpf'] ?? null,
            'memberId' => $raw['memberId'] ?? null,
            'nome_cliente' => $raw['nome_cliente'] ?? null,
            'aluno_encontrado' => $raw['aluno_encontrado'] ?? false,
            'total_debito_ativo' => $raw['total_debito_ativo'] ?? 0,
            'total_debito_ativo_brl' => $raw['total_debito_ativo_brl'] ?? 'R$ 0,00',
            'debtAmount' => $raw['debtAmount'] ?? ($raw['total_debito_ativo'] ?? 0),
            'dias_atraso_atual' => $raw['dias_atraso_atual'] ?? 0,
            'checkoutLinkFullDebt' => $raw['checkoutLinkFullDebt'] ?? ($raw['link_pagamento_divida_total'] ?? $raw['link_pagamento'] ?? null),
            'message' => $raw['message'] ?? null,
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $responseMs = round((microtime(true) - $start) * 1000, 2);
}

if ($asJson) {
    jsonResponse($error === null ? 200 : 400, [
        'ok' => $error === null,
        'action' => $action,
        'cpf' => $cpfInput,
        'memberId' => $memberIdInput,
        'responseTimeMs' => $responseMs,
        'result' => $error === null ? $result : null,
        'error' => $error,
        'logFile' => '/api-evokore/logs/financial.log',
    ]);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teste EVO Financeiro</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f8fafc; color: #0f172a; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; max-width: 900px; }
        .grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        input, select, button { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; }
        button { background: #0f172a; color: #fff; cursor: pointer; }
        pre { white-space: pre-wrap; word-break: break-word; background: #0b1020; color: #e2e8f0; padding: 14px; border-radius: 10px; overflow: auto; }
        .ok { color: #166534; font-weight: bold; }
        .err { color: #b91c1c; font-weight: bold; }
        .hint { color: #475569; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Teste EVO Financeiro</h1>
        <p class="hint">Teste de retorno de status e link. Não exibe token.</p>
        <form method="get">
            <div class="grid">
                <div>
                    <label>Ação</label>
                    <select name="action">
                        <option value="financialStatus" <?= $action === 'financialStatus' ? 'selected' : '' ?>>financialStatus</option>
                        <option value="financialLink" <?= $action === 'financialLink' ? 'selected' : '' ?>>financialLink</option>
                    </select>
                </div>
                <div>
                    <label>memberId (prioridade)</label>
                    <input type="text" name="memberId" value="<?= h($memberIdInput) ?>" placeholder="ex.: 3149471">
                </div>
                <div>
                    <label>CPF</label>
                    <input type="text" name="cpf" value="<?= h($cpfInput) ?>" placeholder="ex.: 543.256.998-13">
                </div>
            </div>
            <input type="hidden" name="format" value="html">
            <div style="margin-top:12px">
                <button type="submit">Executar teste</button>
            </div>
        </form>

        <?php if ($cpfInput !== '' || $memberIdInput !== ''): ?>
            <hr>
            <p>Status: <span class="<?= $error === null ? 'ok' : 'err' ?>"><?= $error === null ? 'OK' : 'ERRO' ?></span></p>
            <p>Tempo de resposta: <strong><?= h((string) $responseMs) ?> ms</strong></p>
            <p>Log esperado: <code>/api-evokore/logs/financial.log</code></p>
            <pre><?= h(json_encode([
                'ok' => $error === null,
                'action' => $action,
                'cpf' => $cpfInput,
                'memberId' => $memberIdInput,
                'responseTimeMs' => $responseMs,
                'result' => $error === null ? $result : null,
                'error' => $error,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}') ?></pre>
        <?php endif; ?>
    </div>
</body>
</html>
