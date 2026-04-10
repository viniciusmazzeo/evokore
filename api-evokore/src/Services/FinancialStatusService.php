<?php
declare(strict_types=1);

namespace EvoKore\Services;

use RuntimeException;
use Throwable;

final class FinancialStatusService
{
    private string $baseUrl;
    private string $dns;
    private string $token;
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
        $this->authMode = strtolower((string) ($config['auth_mode'] ?? $this->env('EVO_AUTH_MODE', 'bearer')));
        $this->dnsHeaderName = (string) ($config['dns_header_name'] ?? $this->env('EVO_DNS_HEADER_NAME', 'DNS'));
        $this->timeoutSeconds = (int) ($config['timeout_seconds'] ?? $this->envInt('EVO_TIMEOUT_SECONDS', 10));
        $this->maxRetries = (int) ($config['max_retries'] ?? $this->envInt('EVO_MAX_RETRIES', 2));
        $this->logFile = (string) ($config['log_file'] ?? dirname(__DIR__, 2) . '/logs/financial.log');

        if ($this->baseUrl === '' || $this->token === '') {
            throw new RuntimeException('Configuracao EVO incompleta.');
        }

        // Garante base da EVO no formato esperado para este serviÃ§o.
        // Ex.: https://evo-integracao-api.w12app.com.br/api/v1
        if (!preg_match('#/api/v\\d+$#', $this->baseUrl)) {
            $this->baseUrl .= '/api/v1';
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function checkByCpf(string $cpf): array
    {
        $cleanCpf = $this->sanitizeCpf($cpf);
        $safe = $this->emptyFinancialResult($cleanCpf, null, null);

        try {
            $memberPayload = $this->request('GET', '/members/basic', [
                'document' => $cleanCpf,
                'take' => '1',
                'skip' => '0',
            ]);

            $memberId = $this->extractMemberId($memberPayload);
            if ($memberId === null) {
                $this->writeLog([
                    'level' => 'info',
                    'type' => 'member_not_found',
                    'cpf' => $cleanCpf,
                ]);
                return $safe;
            }

            $memberName = $this->extractMemberName($memberPayload);
            return $this->checkByResolvedMemberId($memberId, $cleanCpf, $memberName);
        } catch (Throwable $e) {
            $this->writeLog([
                'level' => 'error',
                'type' => 'financial_check_error',
                'cpf' => $cleanCpf,
                'error' => $e->getMessage(),
            ]);
            return $safe;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function checkByMemberId(int|string $memberId, ?string $cpf = null, ?string $memberName = null): array
    {
        $cleanMemberId = $this->sanitizeMemberId($memberId);
        $cleanCpf = $cpf !== null && $cpf !== '' ? $this->sanitizeCpf($cpf) : null;

        try {
            return $this->checkByResolvedMemberId($cleanMemberId, $cleanCpf, $memberName);
        } catch (Throwable $e) {
            $this->writeLog([
                'level' => 'error',
                'type' => 'financial_check_error',
                'memberId' => $cleanMemberId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyFinancialResult($cleanCpf, $cleanMemberId, $memberName);
        }
    }

    /**
     * Endpoint dedicado para obter link de pagamento no formato disponível pela EVO.
     * @return array<string,mixed>
     */
    public function getPaymentLinkByCpf(string $cpf): array
    {
        $cleanCpf = $this->sanitizeCpf($cpf);
        $memberPayload = $this->request('GET', '/members/basic', [
            'document' => $cleanCpf,
            'take' => '1',
            'skip' => '0',
        ]);

        $memberId = $this->extractMemberId($memberPayload);
        if ($memberId === null) {
            return [
                'ok' => false,
                'cpf' => $cleanCpf,
                'memberId' => null,
                'checkoutLinkFullDebt' => null,
                'message' => 'Aluno não encontrado.',
            ];
        }

        $memberName = $this->extractMemberName($memberPayload);
        return $this->getPaymentLinkByResolvedMember($memberId, $cleanCpf, $memberName);
    }

    /**
     * @return array<string,mixed>
     */
    public function getPaymentLinkByMemberId(int|string $memberId, ?string $cpf = null): array
    {
        $cleanMemberId = $this->sanitizeMemberId($memberId);
        $cleanCpf = $cpf !== null && $cpf !== '' ? $this->sanitizeCpf($cpf) : null;
        return $this->getPaymentLinkByResolvedMember($cleanMemberId, $cleanCpf, null);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkByResolvedMemberId(int $memberId, ?string $cpf = null, ?string $memberName = null): array
    {
        $safe = $this->emptyFinancialResult($cpf, $memberId, $memberName);
        $safe['aluno_encontrado'] = true;

        $debtorsPayload = $this->requestByMemberVariants('/receivables/debtors', $memberId, $cpf, 200);
        if ($debtorsPayload === null) {
            $debtorsPayload = [];
        }

        if (($safe['nome_cliente'] ?? null) === null) {
            $safe['nome_cliente'] = $this->extractNameFromDebtors($debtorsPayload, $memberId);
        }

        [$totalDebitos, $maxDelayDays, $totalAmount, $checkoutLink, $checkoutFullLink] = $this->computeOverduesFromDebtors($debtorsPayload, $memberId);
        [$anyDebtAmount, $anyCheckoutFullLink] = $this->extractDebtorFallbackSignals($debtorsPayload, $memberId);

        if ($totalDebitos === 0 && $totalAmount <= 0.0) {
            $financialPayload = $this->requestByMemberVariants('/receivables', $memberId, $cpf, 200);
            if (is_array($financialPayload)) {
                [$totalDebitos, $maxDelayDays, $totalAmount] = $this->computeOverdues($financialPayload);
            }
        }

        $safe['tem_debito'] = $totalDebitos > 0;
        $safe['total_debitos'] = $totalDebitos;
        $safe['total_debito_ativo'] = $totalAmount;
        $safe['total_debito_ativo_brl'] = 'R$ ' . number_format($totalAmount, 2, ',', '.');
        $safe['debtAmount'] = $totalAmount > 0.0 ? $totalAmount : $anyDebtAmount;
        $safe['maior_atraso_dias'] = $maxDelayDays;
        $safe['dias_atraso_atual'] = $maxDelayDays;
        $safe['link_pagamento'] = $this->normalizeCheckoutLink($checkoutLink);
        $fullDebtLink = $checkoutFullLink ?? $anyCheckoutFullLink;
        $fullDebtLink = $this->normalizeCheckoutLink($fullDebtLink);
        $safe['link_pagamento_divida_total'] = $fullDebtLink;
        $safe['checkoutLinkFullDebt'] = $fullDebtLink;

        $this->writeLog([
            'level' => 'info',
            'type' => 'financial_result',
            'cpf' => $cpf,
            'memberId' => $memberId,
            'total_debitos' => $totalDebitos,
            'total_debito_ativo' => $totalAmount,
            'maior_atraso_dias' => $maxDelayDays,
            'link_pagamento' => $checkoutLink,
            'link_pagamento_divida_total' => $fullDebtLink,
        ]);

        return $safe;
    }

    /**
     * @return array<string,mixed>
     */
    private function getPaymentLinkByResolvedMember(int $memberId, ?string $cpf = null, ?string $memberName = null): array
    {
        $debtorsPayload = $this->requestByMemberVariants('/receivables/debtors', $memberId, $cpf, 300);
        $link = null;
        $amount = 0.0;
        $days = 0;

        if (is_array($debtorsPayload)) {
            [$amount, $link] = $this->extractDebtorFallbackSignals($debtorsPayload, $memberId);
            [, $days] = $this->extractMaxDelayAndCount($debtorsPayload, $memberId);
        }

        if ($link === null) {
            $status = $this->checkByResolvedMemberId($memberId, $cpf, $memberName);
            $amount = (float) ($status['total_debito_ativo'] ?? $amount);
            $days = (int) ($status['dias_atraso_atual'] ?? $days);
        }

        return [
            'ok' => $link !== null,
            'cpf' => $cpf,
            'memberId' => $memberId,
            'nome_cliente' => $memberName,
            'debtAmount' => round($amount, 2),
            'debtAmountBrl' => 'R$ ' . number_format(round($amount, 2), 2, ',', '.'),
            'dias_atraso_atual' => $days,
            'checkoutLinkFullDebt' => $link,
            'message' => $link !== null
                ? 'Link de pagamento disponível.'
                : 'A EVO não retornou checkoutLinkFullDebt para este membro no momento.',
        ];
    }

    /**
     * Tenta variações de parâmetro para memberId sem abortar o fluxo.
     * @return array<string,mixed>|null
     */
    private function requestByMemberVariants(string $path, int $memberId, ?string $cpf = null, int $take = 200): ?array
    {
        $isDebtorsPath = str_contains(strtolower($path), 'debtors');
        if ($isDebtorsPath) {
            // Prioriza combinacoes que normalmente retornam checkoutLinkFullDebt.
            $queries = [
                ['membersIds' => (string) $memberId, 'debtStatus' => 'open', 'take' => (string) $take, 'skip' => '0'],
                ['memberIds' => (string) $memberId, 'debtStatus' => 'open', 'take' => (string) $take, 'skip' => '0'],
                ['memberId' => (string) $memberId, 'debtStatus' => 'open', 'take' => (string) $take, 'skip' => '0'],
                ['membersIds' => (string) $memberId, 'take' => (string) $take, 'skip' => '0'],
                ['memberIds' => (string) $memberId, 'take' => (string) $take, 'skip' => '0'],
                ['memberId' => (string) $memberId, 'take' => (string) $take, 'skip' => '0'],
                ['idMember' => (string) $memberId, 'take' => (string) $take, 'skip' => '0'],
                ['memberId' => (string) $memberId, 'debtStatus' => 'Overdue', 'take' => (string) $take, 'skip' => '0'],
                ['memberId' => (string) $memberId, 'status' => '4', 'take' => (string) $take, 'skip' => '0'],
            ];
        } else {
            $queries = [
                ['idMember' => (string) $memberId, 'take' => (string) $take, 'skip' => '0'],
                ['memberId' => (string) $memberId, 'take' => (string) $take, 'skip' => '0'],
                ['membersIds' => (string) $memberId, 'take' => (string) $take, 'skip' => '0'],
                ['memberIds' => (string) $memberId, 'take' => (string) $take, 'skip' => '0'],
            ];
        }
        if ($cpf !== null && $cpf !== '') {
            $queries[] = ['document' => $cpf, 'take' => (string) $take, 'skip' => '0'];
            $queries[] = ['cpf' => $cpf, 'take' => (string) $take, 'skip' => '0'];
        }

        $bestPayload = null;
        $bestRowsCount = -1;

        foreach ($queries as $query) {
            try {
                $payload = $this->request('GET', $path, $query);
                $rowsCount = $this->countRowsFromPayload($payload);
                $hasLink = $isDebtorsPath ? $this->payloadHasCheckoutLink($payload, $memberId) : false;

                $this->writeLog([
                    'level' => 'info',
                    'type' => 'member_query_variant_success',
                    'path' => $path,
                    'memberId' => $memberId,
                    'query' => $query,
                    'rows_count' => $rowsCount,
                    'has_checkout_link' => $hasLink,
                ]);

                if ($rowsCount > $bestRowsCount) {
                    $bestPayload = $payload;
                    $bestRowsCount = $rowsCount;
                }

                if ($hasLink) {
                    return $payload;
                }

                if (!$isDebtorsPath && $rowsCount > 0) {
                    return $payload;
                }
            } catch (Throwable $e) {
                $this->writeLog([
                    'level' => 'warning',
                    'type' => 'member_query_variant_failed',
                    'path' => $path,
                    'memberId' => $memberId,
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $bestPayload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function payloadHasCheckoutLink(array $payload, int $memberId): bool
    {
        $rows = $payload['results'] ?? $payload['items'] ?? $payload['data'] ?? [];
        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowMemberId = (int) ($row['memberId'] ?? $row['idMember'] ?? 0);
            if ($rowMemberId !== 0 && $rowMemberId !== $memberId) {
                continue;
            }

            $checkoutFull = trim((string) ($row['checkoutLinkFullDebt'] ?? ''));
            $checkout = trim((string) ($row['checkoutLink'] ?? ''));
            if ($checkoutFull !== '' || $checkout !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function countRowsFromPayload(array $payload): int
    {
        $rows = $payload['results'] ?? $payload['items'] ?? $payload['data'] ?? $payload;
        return is_array($rows) ? count($rows) : 0;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractNameFromDebtors(array $payload, int $memberId): ?string
    {
        $rows = $payload['results'] ?? $payload['items'] ?? $payload['data'] ?? [];
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowMemberId = (int) ($row['memberId'] ?? $row['idMember'] ?? 0);
            if ($rowMemberId !== 0 && $rowMemberId !== $memberId) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * Fallback: coleta debtAmount e checkoutLinkFullDebt sem filtrar status.
     * @param array<string,mixed> $payload
     * @return array{0:float,1:?string}
     */
    private function extractDebtorFallbackSignals(array $payload, int $memberId): array
    {
        $rows = $payload['results'] ?? $payload['items'] ?? $payload['data'] ?? [];
        if (!is_array($rows)) {
            return [0.0, null];
        }

        $fallbackAmount = 0.0;
        $fallbackFullLink = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowMemberId = (int) ($row['memberId'] ?? $row['idMember'] ?? 0);
            if ($rowMemberId !== 0 && $rowMemberId !== $memberId) {
                continue;
            }

            if ($fallbackAmount <= 0.0) {
                $amount = $this->toFloat($row['debtAmount'] ?? null);
                if ($amount === null || $amount <= 0.0) {
                    $amount = $this->extractAmount($row);
                }
                if ($amount > 0.0) {
                    $fallbackAmount = round($amount, 2);
                }
            }

            if ($fallbackFullLink === null) {
                $full = trim((string) ($row['checkoutLinkFullDebt'] ?? $row['checkoutLink'] ?? $row['paymentLink'] ?? ''));
                if ($full !== '') {
                    $fallbackFullLink = $full;
                }
            }

            if ($fallbackAmount > 0.0 && $fallbackFullLink !== null) {
                break;
            }
        }

        return [$fallbackAmount, $this->normalizeCheckoutLink($fallbackFullLink)];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:int,1:int}
     */
    private function extractMaxDelayAndCount(array $payload, int $memberId): array
    {
        $rows = $payload['results'] ?? $payload['items'] ?? $payload['data'] ?? [];
        if (!is_array($rows)) {
            return [0, 0];
        }

        $count = 0;
        $maxDelay = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowMemberId = (int) ($row['memberId'] ?? $row['idMember'] ?? 0);
            if ($rowMemberId !== 0 && $rowMemberId !== $memberId) {
                continue;
            }
            $status = $this->resolveStatusText($row, ['debtStatus', 'status', 'receivableStatus']);
            if ($this->isNonActiveDebtStatus($status)) {
                continue;
            }
            $count++;
            $d = (int) ($row['daysLate'] ?? 0);
            if ($d > $maxDelay) {
                $maxDelay = $d;
            }
        }

        return [$count, $maxDelay];
    }

    private function normalizeCheckoutLink(?string $link): ?string
    {
        if ($link === null) {
            return null;
        }

        $clean = trim($link);
        if ($clean === '') {
            return null;
        }

        $clean = str_replace(' ', '%20', $clean);
        if (!preg_match('#^https?://#i', $clean)) {
            return null;
        }

        return $clean;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyFinancialResult(?string $cpf, ?int $memberId, ?string $memberName): array
    {
        return [
            'cpf' => $cpf,
            'memberId' => $memberId,
            'aluno_encontrado' => false,
            'nome_cliente' => $memberName,
            'tem_debito' => false,
            'total_debitos' => 0,
            'total_debito_ativo' => 0.0,
            'total_debito_ativo_brl' => 'R$ 0,00',
            'debtAmount' => 0.0,
            'maior_atraso_dias' => 0,
            'dias_atraso_atual' => 0,
            'link_pagamento' => null,
            'link_pagamento_divida_total' => null,
            'checkoutLinkFullDebt' => null,
        ];
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        $headers = $this->buildHeaders();
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
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADER => false,
            ]);

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                $lastError = 'cURL error ' . $errno . ': ' . $error;
                if ($attempt <= $this->maxRetries) {
                    usleep(200000);
                    continue;
                }
                throw new RuntimeException($lastError);
            }

            $body = is_string($raw) ? $raw : '';

            $this->writeLog([
                'level' => 'info',
                'type' => 'financial_request',
                'attempt' => $attempt,
                'method' => $method,
                'url' => $url,
                'http_code' => $httpCode,
                'request_headers' => $this->redactHeadersForLog($headers),
                'response' => $this->safeLogResponse($body),
            ]);

            if ($httpCode >= 500 && $attempt <= $this->maxRetries) {
                usleep(200000);
                continue;
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                throw new RuntimeException('EVO HTTP ' . $httpCode . ': ' . $this->extractErrorMessage($body));
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Resposta EVO invalida.');
            }

            return $decoded;
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Erro desconhecido em request EVO.');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractMemberId(array $payload): ?int
    {
        if (isset($payload['idMember']) && is_scalar($payload['idMember'])) {
            return (int) $payload['idMember'];
        }

        $items = $payload['items'] ?? $payload['data'] ?? $payload;
        if (!is_array($items)) {
            return null;
        }

        foreach ($items as $row) {
            if (is_array($row) && isset($row['idMember']) && is_scalar($row['idMember'])) {
                return (int) $row['idMember'];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:int,1:int,2:float}
     */
    private function computeOverdues(array $payload): array
    {
        $rows = $payload['items'] ?? $payload['data'] ?? $payload;
        if (!is_array($rows)) {
            return [0, 0, 0.0];
        }

        $today = new \DateTimeImmutable('today');
        $total = 0;
        $maxDelay = 0;
        $totalAmount = 0.0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = $this->resolveStatusText($row, ['status', 'receivableStatus', 'debtStatus']);
            if ($this->isNonActiveDebtStatus($status)) {
                continue;
            }

            $dueRaw = (string) ($row['dueDate'] ?? $row['due_date'] ?? $row['expirationDate'] ?? '');
            $amount = $this->extractOutstandingFromReceivable($row);
            if ($amount <= 0.0) {
                continue;
            }

            $dueDate = $this->parseDate($dueRaw);
            $delay = 0;
            if ($dueDate instanceof \DateTimeImmutable && $dueDate < $today) {
                $delay = (int) $today->diff($dueDate)->format('%a');
            }

            $total++;
            $totalAmount += $amount;
            if ($delay > $maxDelay) {
                $maxDelay = $delay;
            }
        }

        return [$total, $maxDelay, round($totalAmount, 2)];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:int,1:int,2:float,3:?string,4:?string}
     */
    private function computeOverduesFromDebtors(array $payload, int $memberId): array
    {
        $rows = $payload['results'] ?? $payload['items'] ?? $payload['data'] ?? [];
        if (!is_array($rows)) {
            return [0, 0, 0.0, null, null];
        }

        $total = 0;
        $maxDelay = 0;
        $totalAmount = 0.0;
        $checkoutLink = null;
        $checkoutFull = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowMemberId = (int) ($row['memberId'] ?? $row['idMember'] ?? 0);
            if ($rowMemberId !== 0 && $rowMemberId !== $memberId) {
                continue;
            }

            $status = $this->resolveStatusText($row, ['debtStatus', 'status', 'receivableStatus']);
            if ($this->isNonActiveDebtStatus($status)) {
                continue;
            }

            $daysLate = (int) ($row['daysLate'] ?? 0);
            $debtAmount = $this->toFloat($row['debtAmount'] ?? null);
            if ($debtAmount === null || $debtAmount <= 0.0) {
                $debtAmount = $this->extractAmount($row);
            }

            if ($debtAmount <= 0.0) {
                continue;
            }

            $totalAmount += $debtAmount;
            $total++;
            if ($daysLate > $maxDelay) {
                $maxDelay = $daysLate;
            }

            $link = trim((string) ($row['checkoutLink'] ?? ''));
            $full = trim((string) ($row['checkoutLinkFullDebt'] ?? ''));
            if ($checkoutLink === null && $link !== '') {
                $checkoutLink = $link;
            }
            if ($checkoutFull === null && $full !== '') {
                $checkoutFull = $full;
            }
        }

        return [$total, $maxDelay, round($totalAmount, 2), $checkoutLink, $checkoutFull];
    }

    private function isNonActiveDebtStatus(string $status): bool
    {
        if ($status === '') {
            return false;
        }

        $nonActiveTerms = [
            'paid',
            'pago',
            'received',
            'recebido',
            'settled',
            'quitado',
            'recovered',
            'recuperado',
            'canceled',
            'cancelado',
            'cancelled',
            'estornado',
            'refunded',
            'refused',
            'chargeback',
        ];

        foreach ($nonActiveTerms as $term) {
            if (str_contains($status, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $keys
     */
    private function resolveStatusText(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if (is_string($value)) {
                return strtolower(trim($value));
            }
            if (is_array($value)) {
                foreach (['name', 'status', 'description'] as $inner) {
                    if (isset($value[$inner]) && is_string($value[$inner])) {
                        return strtolower(trim($value[$inner]));
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractMemberName(array $payload): ?string
    {
        $first = trim((string) ($payload['firstName'] ?? $payload['registerName'] ?? ''));
        $last = trim((string) ($payload['lastName'] ?? $payload['registerLastName'] ?? ''));
        $full = trim($first . ' ' . $last);
        if ($full !== '') {
            return $full;
        }

        $items = $payload['items'] ?? $payload['data'] ?? $payload;
        if (is_array($items)) {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $first = trim((string) ($row['firstName'] ?? $row['registerName'] ?? ''));
                $last = trim((string) ($row['lastName'] ?? $row['registerLastName'] ?? ''));
                $full = trim($first . ' ' . $last);
                if ($full !== '') {
                    return $full;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractAmount(array $row): float
    {
        // Campos mais comuns em integraÃ§Ãµes financeiras EVO (flat e nested).
        $priorityKeys = [
            'debtAmount',
            'debt_value',
            'pendingValue',
            'ammount',
            'remainingAmount',
            'amountPending',
            'pendingAmount',
            'openAmount',
            'outstandingAmount',
            'balance',
            'amountDue',
            'amountToPay',
            'netValue',
            'originalValue',
            'value',
            'amount',
        ];

        foreach ($priorityKeys as $key) {
            $found = $this->findNumericByKeyRecursive($row, $key);
            if ($found !== null && $found > 0) {
                return $found;
            }
        }

        // Fallback: varre chaves que parecem representar valor monetÃ¡rio.
        $fallback = $this->findLikelyAmountRecursive($row);
        return $fallback ?? 0.0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractOutstandingFromReceivable(array $row): float
    {
        $amount = $this->toFloat($row['ammount'] ?? $row['amount'] ?? null);
        $amountPaid = $this->toFloat($row['ammountPaid'] ?? $row['amountPaid'] ?? null);

        if ($amount !== null) {
            if ($amountPaid !== null) {
                $remaining = $amount - $amountPaid;
                if ($remaining > 0) {
                    return round($remaining, 2);
                }
            } elseif ($amount > 0) {
                return round($amount, 2);
            }
        }

        return $this->extractAmount($row);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function findNumericByKeyRecursive(array $data, string $targetKey): ?float
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && strcasecmp($key, $targetKey) === 0) {
                $parsed = $this->toFloat($value);
                if ($parsed !== null) {
                    return $parsed;
                }
            }

            if (is_array($value)) {
                $found = $this->findNumericByKeyRecursive($value, $targetKey);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function findLikelyAmountRecursive(array $data): ?float
    {
        $best = null;

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $nested = $this->findLikelyAmountRecursive($value);
                if ($nested !== null && ($best === null || $nested > $best)) {
                    $best = $nested;
                }
                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            $k = strtolower($key);
            if (
                str_contains($k, 'amount') ||
                str_contains($k, 'value') ||
                str_contains($k, 'valor') ||
                str_contains($k, 'saldo')
            ) {
                $parsed = $this->toFloat($value);
                if ($parsed !== null && $parsed > 0 && ($best === null || $parsed > $best)) {
                    $best = $parsed;
                }
            }
        }

        return $best;
    }

    private function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $raw = str_replace('R$', '', $raw);
        $raw = preg_replace('/\s+/', '', $raw) ?? '';

        // Detecta formato BR "1.234,56"
        if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d+$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            // Formato EN "1,234.56" ou "1234.56"
            $raw = str_replace(',', '', $raw);
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    private function sanitizeMemberId(int|string $memberId): int
    {
        $digits = preg_replace('/\D+/', '', (string) $memberId) ?? '';
        if ($digits === '' || (int) $digits <= 0) {
            throw new RuntimeException('memberId invalido.');
        }

        return (int) $digits;
    }

    private function sanitizeCpf(string $cpf): string
    {
        $digits = preg_replace('/\D+/', '', $cpf) ?? '';
        if (strlen($digits) !== 11) {
            throw new RuntimeException('CPF invalido. Use 11 digitos.');
        }

        return $digits;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = ['Y-m-d', 'Y-m-d\TH:i:s', \DateTimeInterface::ATOM];
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->setTime(0, 0, 0);
            }
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($ts)->setTime(0, 0, 0);
    }

    /**
     * @param array<string,string> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
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
            'Content-Type: application/json',
        ];

        if ($this->authMode === 'basic') {
            $user = $this->dns !== '' ? $this->dns : 'default';
            $pass = rtrim($this->token, ':');
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if ($this->dns !== '') {
            $headers[] = $this->dnsHeaderName . ': ' . $this->dns;
        }

        return $headers;
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
            // Fail-safe: erros de log nao derrubam o fluxo.
        }
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
    private function safeLogResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['raw' => substr($body, 0, 2000)];
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
        return $plain !== '' ? substr($plain, 0, 300) : 'Erro HTTP sem corpo.';
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

