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
    private string $proRequestHeaderName;
    private string $proRequestHeaderValue;
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
        $this->proRequestHeaderName = $this->normalizeHeaderName((string) ($config['pro_request_header_name'] ?? $this->env('EVO_PRO_REQUEST_HEADER_NAME', 'evoapipro-request')));
        $this->proRequestHeaderValue = trim((string) ($config['pro_request_header_value'] ?? $this->env('EVO_PRO_REQUEST_HEADER_VALUE', '')));
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
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createPlanSale(array $payload): array
    {
        $endpoint = trim($this->env('EVO_SALE_ENDPOINT', '/api/v2/sales'));
        $extraQuery = $this->defaultSaleQuery($endpoint);

        try {
            return $this->requestEndpoint('POST', $endpoint, $payload, $extraQuery);
        } catch (RuntimeException $e) {
            $message = strtolower($e->getMessage());

            if ($this->shouldTryPaymentFallback($message)) {
                foreach ($this->salePaymentFallbacks((int) ($payload['payment'] ?? 1)) as $paymentCode) {
                    $retryPayload = $payload;
                    $retryPayload['payment'] = $paymentCode;
                    try {
                        return $this->requestEndpoint('POST', $endpoint, $retryPayload, $extraQuery);
                    } catch (RuntimeException) {
                        // tenta proximo tipo de pagamento
                    }
                }
            }

            if (!str_contains($e->getMessage(), 'EVO HTTP 404') && !str_contains($e->getMessage(), 'EVO HTTP 405')) {
                throw $e;
            }

            $fallback = trim($this->env('EVO_SALE_ENDPOINT_FALLBACK', '/api/v1/sales'));
            if ($fallback === '' || $fallback === $endpoint) {
                throw $e;
            }
            $fallbackQuery = $this->defaultSaleQuery($fallback);

            try {
                return $this->requestEndpoint('POST', $fallback, $payload, $fallbackQuery);
            } catch (RuntimeException $fallbackError) {
                $fallbackMessage = strtolower($fallbackError->getMessage());
                if ($this->shouldTryPaymentFallback($fallbackMessage)) {
                    foreach ($this->salePaymentFallbacks((int) ($payload['payment'] ?? 1)) as $paymentCode) {
                        $retryPayload = $payload;
                        $retryPayload['payment'] = $paymentCode;
                        try {
                            return $this->requestEndpoint('POST', $fallback, $retryPayload, $fallbackQuery);
                        } catch (RuntimeException) {
                            // tenta proximo tipo de pagamento
                        }
                    }
                }

                throw $fallbackError;
            }
        }
    }

    /**
     * @return array{member_id:string,created:bool,lookup_payload:array<string,mixed>}
     */
    public function ensureMemberByCpf(string $cpf, string $customerName, ?string $phone = null, ?string $email = null): array
    {
        $document = $this->sanitizeCpf($cpf);
        $name = trim($customerName);
        $phoneValue = $phone !== null ? trim($phone) : '';
        $emailValue = $email !== null ? trim($email) : '';

        $lookup = $this->request('GET', '/api/v1/members/basic', [
            'document' => $document,
            'take' => '5',
            'skip' => '0',
        ]);
        $memberId = $this->extractIdMemberByDocument($lookup, $document) ?? $this->extractIdMember($lookup);
        if ($memberId !== '') {
            return [
                'member_id' => $memberId,
                'created' => false,
                'lookup_payload' => $lookup,
            ];
        }

        $prospectResponse = $this->createProspect([
            'cpf' => $document,
            'customer_name' => $name,
            'phone' => $phoneValue,
            'email' => $emailValue,
        ]);

        $prospectId = $this->extractProspectId($prospectResponse);
        $convertResponse = $this->convertProspectToMember($prospectId, [
            'cpf' => $document,
            'customer_name' => $name,
            'phone' => $phoneValue,
            'email' => $emailValue,
        ]);

        $memberId = $this->extractIdMember($convertResponse);
        if ($memberId !== '') {
            return [
                'member_id' => $memberId,
                'created' => true,
                'lookup_payload' => $convertResponse,
            ];
        }

        $lookupAfterConvert = [];
        for ($i = 0; $i < 4; $i++) {
            $lookupAfterConvert = $this->request('GET', '/api/v1/members/basic', [
                'document' => $document,
                'take' => '5',
                'skip' => '0',
            ]);
            $memberId = $this->extractIdMemberByDocument($lookupAfterConvert, $document) ?? $this->extractIdMember($lookupAfterConvert);
            if ($memberId !== '') {
                return [
                    'member_id' => $memberId,
                    'created' => true,
                    'lookup_payload' => $lookupAfterConvert,
                ];
            }
            usleep(250000);
        }

        throw new RuntimeException('Nao foi possivel obter memberId na EVO apos criar/converter prospect.');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createTrialClass(array $payload): array
    {
        $cpf = $this->sanitizeCpf((string) ($payload['cpf'] ?? ''));
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $preferredDate = $this->sanitizeDate((string) ($payload['preferred_date'] ?? ''), 'preferred_date');
        $preferredTime = $this->sanitizeHourMinute((string) ($payload['preferred_time'] ?? ''), 'preferred_time');
        $service = trim((string) ($payload['service'] ?? 'Aula Experimental'));
        $activity = trim((string) ($payload['activity'] ?? 'Aula Experimental'));
        $activityExist = (bool) ($payload['activity_exist'] ?? false);
        $idBranch = (int) ($payload['id_branch'] ?? $this->envInt('EVO_DEFAULT_BRANCH_ID', 1));

        if ($customerName === '') {
            throw new RuntimeException('customer_name e obrigatorio para agendamento de aula experimental na EVO.');
        }
        if ($service === '') {
            throw new RuntimeException('service e obrigatorio para agendamento de aula experimental na EVO.');
        }
        if ($activity === '') {
            throw new RuntimeException('activity e obrigatorio para agendamento de aula experimental na EVO.');
        }

        $prospect = $this->createProspect([
            'cpf' => $cpf,
            'customer_name' => $customerName,
            'phone' => $phone,
            'email' => $email,
        ]);
        $prospectId = (int) $this->extractProspectId($prospect);

        $trialPayload = [
            'idProspect' => $prospectId,
            'activityDate' => $preferredDate . ' ' . $preferredTime,
            'service' => $service,
            'activity' => $activity,
            'activityExist' => $activityExist,
        ];
        if ($idBranch > 0) {
            $trialPayload['idBranch'] = $idBranch;
        }
        $trialQuery = $this->normalizeQueryParams($trialPayload);

        $attemptErrors = [];
        foreach ($this->trialCreateEndpoints() as $endpoint) {
            try {
                $result = $this->request('POST', $endpoint, $trialQuery, $trialPayload);
                $result['idProspect'] = $result['idProspect'] ?? $prospectId;
                return $result;
            } catch (RuntimeException $e) {
                $attemptErrors[] = sprintf('%s => %s', $endpoint, $e->getMessage());
            }
        }

        if ($this->isTrialEndpointUnavailable($attemptErrors)) {
            return [
                'fallback_mode' => 'prospect',
                'message' => 'Endpoint de aula experimental indisponivel na EVO. Prospect criado para tratativa comercial.',
                'prospect' => $prospect,
                'idProspect' => $prospectId,
                'activityDate' => $preferredDate . ' ' . $preferredTime,
                'service' => $service,
                'activity' => $activity,
            ];
        }

        $suffix = $attemptErrors !== []
            ? ' Tentativas: ' . implode(' | ', array_slice($attemptErrors, 0, 4))
            : '';

        throw new RuntimeException('Falha ao cadastrar aula experimental na EVO.' . $suffix);
    }

    /**
     * @return string[]
     */
    public function listAvailableTrialTimeSlots(string $date, ?int $idBranch = null): array
    {
        $targetDate = $this->sanitizeDate($date, 'date');
        $query = [
            'onlyAvailables' => 'true',
            'date' => $targetDate,
            'take' => '500',
        ];

        if ($idBranch !== null && $idBranch > 0) {
            $query['idBranch'] = (string) $idBranch;
        }

        $response = $this->request('GET', '/api/v1/activities/schedule', $query);
        $slots = $this->extractScheduleTimeSlots($response, $targetDate);
        sort($slots, SORT_STRING);

        return array_values(array_unique($slots));
    }

    /**
     * @return array<string,mixed>
     */
    public function getUnitOperationalInfo(?int $idBranch = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        // Modo "sem filtro de datas": tenta buscar grade geral sem parametro de data
        // e combina com janela curta para aumentar cobertura sem depender de um unico formato.
        $rows = [];
        if ($dateFrom === null && $dateTo === null) {
            $allRows = $this->fetchScheduleRowsWithoutDate($idBranch);
            foreach ($allRows as $row) {
                $rows[] = $row;
            }
        }

        $defaultWindowDays = max(1, (int) $this->envInt('EVO_UNIT_INFO_DEFAULT_WINDOW_DAYS', 6));
        $from = $this->sanitizeDate($dateFrom ?? date('Y-m-d'), 'date_from');
        $to = $this->sanitizeDate($dateTo ?? date('Y-m-d', strtotime($from . ' +' . $defaultWindowDays . ' days')), 'date_to');

        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        if ($fromTs === false || $toTs === false) {
            throw new RuntimeException('Periodo invalido para consulta operacional da unidade.');
        }
        if ($toTs < $fromTs) {
            throw new RuntimeException('date_to deve ser maior ou igual a date_from.');
        }

        // Limita janela para evitar carga excessiva na EVO.
        $maxDays = max(1, (int) $this->envInt('EVO_UNIT_INFO_MAX_WINDOW_DAYS', 7));
        if ((int) floor(($toTs - $fromTs) / 86400) > $maxDays) {
            $toTs = strtotime($from . ' +' . $maxDays . ' days');
            if ($toTs === false) {
                throw new RuntimeException('Nao foi possivel limitar o periodo de consulta.');
            }
            $to = date('Y-m-d', $toTs);
        }

        for ($cursor = $fromTs; $cursor <= $toTs; $cursor += 86400) {
            $targetDate = date('Y-m-d', $cursor);
            $query = [
                'onlyAvailables' => strtolower(trim($this->env('EVO_UNIT_INFO_ONLY_AVAILABLES', 'false'))) === 'true' ? 'true' : 'false',
                'date' => $targetDate,
                'take' => '500',
            ];
            if ($idBranch !== null && $idBranch > 0) {
                $query['idBranch'] = (string) $idBranch;
            }

            try {
                $response = $this->request('GET', '/api/v1/activities/schedule', $query);
                $dailyRows = [];
                $this->collectScheduleRows($response, $dailyRows);
                foreach ($dailyRows as $row) {
                    $rows[] = $row;
                }
            } catch (RuntimeException $e) {
                $msg = strtolower($e->getMessage());
                // Se limite da EVO estourar, devolve o que conseguiu coletar ate aqui.
                if (str_contains($msg, 'evo http 429')) {
                    if ($rows !== []) {
                        break;
                    }
                    throw $e;
                }
                throw $e;
            }
        }

        return $this->buildOperationalInfoFromRows($rows, $from, $to);
    }

    /**
     * Busca metadados da unidade diretamente na EVO (endereco/contatos/infraestrutura),
     * tentando multiplos endpoints e formatos de query.
     *
     * @return array<string,mixed>
     */
    public function getUnitMetadata(?int $idBranch = null): array
    {
        $errors = [];
        foreach ($this->unitInfoEndpoints() as $endpoint) {
            foreach ($this->unitInfoQueryCandidates($idBranch) as $query) {
                try {
                    $payload = $this->request('GET', $endpoint, $query);
                    $parsed = $this->parseUnitMetadataPayload($payload, $idBranch);
                    if ($parsed['has_data'] ?? false) {
                        $parsed['source_endpoint'] = $endpoint;
                        $parsed['source_query'] = $query;
                        return $parsed;
                    }
                } catch (RuntimeException $e) {
                    $queryText = $query !== [] ? ('?' . http_build_query($query)) : '';
                    $errors[] = $endpoint . $queryText . ': ' . $e->getMessage();
                }
            }
        }

        return [
            'has_data' => false,
            'address' => null,
            'contacts' => null,
            'infrastructure' => [],
            'has_parking' => null,
            'source_endpoint' => null,
            'source_query' => null,
            'errors' => array_slice($errors, 0, 5),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchScheduleRowsWithoutDate(?int $idBranch = null): array
    {
        $query = [
            'onlyAvailables' => 'true',
            'take' => '1000',
        ];
        if ($idBranch !== null && $idBranch > 0) {
            $query['idBranch'] = (string) $idBranch;
        }

        try {
            $response = $this->request('GET', '/api/v1/activities/schedule', $query);
        } catch (RuntimeException) {
            return [];
        }

        $rows = [];
        $this->collectScheduleRows($response, $rows);

        return $rows;
    }

    /**
     * @return string[]
     */
    private function unitInfoEndpoints(): array
    {
        $raw = trim($this->env('EVO_UNIT_INFO_ENDPOINTS', '/api/v1/branches,/api/v1/branch'));
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');

        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = '/' . ltrim($part, '/');
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : ['/api/v1/branches', '/api/v1/branch'];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function unitInfoQueryCandidates(?int $idBranch): array
    {
        if ($idBranch === null || $idBranch <= 0) {
            return [[], ['take' => '100', 'skip' => '0']];
        }

        return [
            ['idBranch' => (string) $idBranch],
            ['branchId' => (string) $idBranch],
            [],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function parseUnitMetadataPayload(array $payload, ?int $idBranch): array
    {
        $nodes = [];
        $this->collectAssociativeNodes($payload, $nodes);

        $best = null;
        $bestScore = -1;
        foreach ($nodes as $node) {
            $score = $this->scoreUnitMetadataNode($node, $idBranch);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $node;
            }
        }

        if (!is_array($best) || $bestScore < 3) {
            return [
                'has_data' => false,
                'address' => null,
                'contacts' => null,
                'infrastructure' => [],
                'has_parking' => null,
            ];
        }

        $address = $this->extractAddressFromNode($best);
        $contacts = $this->extractContactsFromNode($best);
        $infrastructure = $this->extractInfrastructureFromNode($best);
        $hasParking = $this->extractHasParkingFromNode($best, $infrastructure);

        return [
            'has_data' => ($address !== null) || ($contacts !== null) || ($infrastructure !== []) || ($hasParking !== null),
            'address' => $address,
            'contacts' => $contacts,
            'infrastructure' => $infrastructure,
            'has_parking' => $hasParking,
        ];
    }

    /**
     * @param mixed $node
     * @param array<int,array<string,mixed>> $nodes
     */
    private function collectAssociativeNodes(mixed $node, array &$nodes): void
    {
        if (!is_array($node)) {
            return;
        }
        if ($this->isAssociativeArray($node)) {
            $nodes[] = $node;
        }
        foreach ($node as $value) {
            $this->collectAssociativeNodes($value, $nodes);
        }
    }

    /**
     * @param array<string,mixed> $node
     */
    private function scoreUnitMetadataNode(array $node, ?int $idBranch): int
    {
        $score = 0;

        $idCandidates = ['idBranch', 'branchId', 'idFilial', 'unitId', 'idUnit', 'id'];
        foreach ($idCandidates as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            $score += 2;
            if ($idBranch !== null && $idBranch > 0 && (string) $node[$key] === (string) $idBranch) {
                $score += 10;
            }
        }

        foreach (['address', 'logradouro', 'street', 'district', 'bairro', 'city', 'cidade', 'state', 'uf', 'zip', 'cep'] as $key) {
            if (isset($node[$key]) && trim((string) $node[$key]) !== '') {
                $score += 3;
            }
        }
        foreach (['phone', 'telefone', 'cellphone', 'whatsapp', 'email'] as $key) {
            if (isset($node[$key]) && trim((string) $node[$key]) !== '') {
                $score += 2;
            }
        }
        foreach (['infrastructure', 'facilities', 'features', 'differentials'] as $key) {
            if (isset($node[$key])) {
                $score += 2;
            }
        }

        return $score;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>|null
     */
    private function extractAddressFromNode(array $node): ?array
    {
        $street = $this->readFirstString($node, ['street', 'logradouro', 'address', 'endereco']);
        $number = $this->readFirstString($node, ['number', 'numero']);
        $complement = $this->readFirstString($node, ['complement', 'complemento']);
        $district = $this->readFirstString($node, ['district', 'bairro', 'neighborhood']);
        $city = $this->readFirstString($node, ['city', 'cidade']);
        $state = $this->readFirstString($node, ['state', 'uf']);
        $zip = $this->readFirstString($node, ['zip', 'zipCode', 'cep', 'postalCode']);
        $formatted = $this->readFirstString($node, ['formattedAddress', 'fullAddress', 'addressComplete']);

        if ($street === null && $district === null && $city === null && $formatted === null) {
            return null;
        }

        return [
            'street' => $street,
            'number' => $number,
            'complement' => $complement,
            'district' => $district,
            'city' => $city,
            'state' => $state,
            'zip_code' => $zip,
            'formatted' => $formatted,
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>|null
     */
    private function extractContactsFromNode(array $node): ?array
    {
        $phone = $this->readFirstString($node, ['phone', 'telefone', 'tel']);
        $whatsapp = $this->readFirstString($node, ['whatsapp', 'whatsApp', 'phoneWhatsapp', 'telefoneWhatsapp']);
        $email = $this->readFirstString($node, ['email', 'mail']);

        if ($phone === null && $whatsapp === null && $email === null) {
            return null;
        }

        return [
            'phone' => $phone,
            'whatsapp' => $whatsapp,
            'email' => $email,
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @return string[]
     */
    private function extractInfrastructureFromNode(array $node): array
    {
        $values = [];
        foreach (['infrastructure', 'facilities', 'features', 'differentials', 'benefits'] as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            $raw = $node[$key];
            if (is_string($raw)) {
                foreach (preg_split('/[,;\n\r]+/', $raw) ?: [] as $part) {
                    $text = trim((string) $part);
                    if ($text !== '') {
                        $values[] = $text;
                    }
                }
                continue;
            }
            if (is_array($raw)) {
                foreach ($raw as $item) {
                    if (is_string($item)) {
                        $text = trim($item);
                        if ($text !== '') {
                            $values[] = $text;
                        }
                    } elseif (is_array($item)) {
                        foreach (['name', 'description', 'title', 'text'] as $innerKey) {
                            if (!isset($item[$innerKey])) {
                                continue;
                            }
                            $text = trim((string) $item[$innerKey]);
                            if ($text !== '') {
                                $values[] = $text;
                                break;
                            }
                        }
                    }
                }
            }
        }

        $values = array_values(array_unique(array_filter(array_map('trim', $values), static fn (string $v): bool => $v !== '')));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    /**
     * @param array<string,mixed> $node
     * @param string[] $infrastructure
     */
    private function extractHasParkingFromNode(array $node, array $infrastructure): ?bool
    {
        foreach (['hasParking', 'parking', 'estacionamento'] as $key) {
            if (!array_key_exists($key, $node)) {
                continue;
            }
            $parsed = $this->normalizeBoolLike($node[$key]);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        foreach ($infrastructure as $item) {
            $up = strtoupper($item);
            if (str_contains($up, 'ESTACIONAMENTO') || str_contains($up, 'PARKING')) {
                return true;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $node
     * @param string[] $keys
     */
    private function readFirstString(array $node, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            $value = trim((string) $node[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function buildOperationalInfoFromRows(array $rows, ?string $forcedFrom, ?string $forcedTo): array
    {
        $classes = [];
        $teachers = [];
        $slotsByDate = [];

        foreach ($rows as $row) {
            $dateOnly = $this->extractRowDate($row);
            if ($dateOnly === null) {
                continue;
            }

            $slotsByDate[$dateOnly] = $slotsByDate[$dateOnly] ?? [];

            $time = $this->extractRowTime($row);
            if ($time !== null) {
                $slotsByDate[$dateOnly][] = $time;
            }

            $className = $this->extractRowClassName($row);
            if ($className !== null) {
                $classes[] = $className;
            }

            $teacherName = $this->extractRowTeacherName($row);
            if ($teacherName !== null) {
                $teachers[] = $teacherName;
            }
        }

        ksort($slotsByDate, SORT_STRING);
        $scheduleByDate = [];
        foreach ($slotsByDate as $date => $slots) {
            sort($slots, SORT_STRING);
            $uniqueSlots = array_values(array_unique($slots));
            $scheduleByDate[] = [
                'date' => $date,
                'weekday' => $this->weekdayName($date),
                'first_time' => $uniqueSlots[0] ?? null,
                'last_time' => $uniqueSlots !== [] ? $uniqueSlots[count($uniqueSlots) - 1] : null,
                'time_slots' => $uniqueSlots,
            ];
        }

        $classes = array_values(array_unique(array_filter(array_map('trim', $classes), static fn (string $v): bool => $v !== '')));
        sort($classes, SORT_NATURAL | SORT_FLAG_CASE);

        $teachers = array_values(array_unique(array_filter(array_map('trim', $teachers), static fn (string $v): bool => $v !== '')));
        sort($teachers, SORT_NATURAL | SORT_FLAG_CASE);

        $dates = array_map(static fn (array $item): string => (string) ($item['date'] ?? ''), $scheduleByDate);
        $dates = array_values(array_filter($dates, static fn (string $v): bool => $v !== ''));
        sort($dates, SORT_STRING);
        $computedFrom = $dates[0] ?? null;
        $computedTo = $dates !== [] ? $dates[count($dates) - 1] : null;

        return [
            'date_from' => $forcedFrom ?? $computedFrom,
            'date_to' => $forcedTo ?? $computedTo,
            'days' => $dates,
            'class_types' => $classes,
            'teachers' => $teachers,
            'schedule' => $scheduleByDate,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createMember(array $payload): array
    {
        $cpf = $this->sanitizeCpf((string) ($payload['cpf'] ?? ''));
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));

        if ($customerName === '') {
            throw new RuntimeException('customer_name e obrigatorio para cadastro de membro na EVO.');
        }

        $endpoints = $this->memberCreateEndpoints();
        $attemptErrors = [];

        foreach ($endpoints as $endpoint) {
            foreach ($this->memberPayloadCandidates($cpf, $customerName, $phone, $email) as $candidate) {
                try {
                    return $this->request('POST', $endpoint, [], $candidate);
                } catch (RuntimeException $e) {
                    $attemptErrors[] = sprintf('%s => %s', $endpoint, $e->getMessage());
                }
            }
        }

        $suffix = $attemptErrors !== []
            ? ' Tentativas: ' . implode(' | ', array_slice($attemptErrors, 0, 4))
            : '';

        throw new RuntimeException('Falha ao cadastrar membro na EVO.' . $suffix);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createProspect(array $payload): array
    {
        $cpf = $this->sanitizeCpf((string) ($payload['cpf'] ?? ''));
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($payload['phone'] ?? '')) ?? '';
        $email = trim((string) ($payload['email'] ?? ''));
        $emailValue = $email !== '' ? $email : sprintf('sem-email-%s@evokore.local', $cpf);

        if ($customerName === '') {
            throw new RuntimeException('customer_name e obrigatorio para cadastro de prospect na EVO.');
        }

        $attemptErrors = [];
        foreach ($this->prospectCreateEndpoints() as $endpoint) {
            foreach ($this->prospectPayloadCandidates($cpf, $customerName, $phone, $emailValue) as $candidate) {
                try {
                    return $this->request('POST', $endpoint, [], $candidate);
                } catch (RuntimeException $e) {
                    $attemptErrors[] = sprintf('%s => %s', $endpoint, $e->getMessage());
                }
            }
        }

        $suffix = $attemptErrors !== []
            ? ' Tentativas: ' . implode(' | ', array_slice($attemptErrors, 0, 4))
            : '';

        throw new RuntimeException('Falha ao cadastrar prospect na EVO.' . $suffix);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function convertProspectToMember(string $prospectId, array $payload = []): array
    {
        $prospectId = trim($prospectId);
        if ($prospectId === '') {
            throw new RuntimeException('prospectId vazio para conversao em member.');
        }

        $cpf = preg_replace('/\D+/', '', (string) ($payload['cpf'] ?? '')) ?? '';
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($payload['phone'] ?? '')) ?? '';
        $email = trim((string) ($payload['email'] ?? ''));

        $attemptErrors = [];
        foreach ($this->prospectConvertEndpoints() as $endpoint) {
            // Alguns ambientes EVO validam IdProspect no query string, nao no body JSON.
            try {
                return $this->request('POST', $endpoint, ['IdProspect' => $prospectId]);
            } catch (RuntimeException $e) {
                $attemptErrors[] = sprintf('%s?IdProspect => %s', $endpoint, $e->getMessage());
            }

            foreach ($this->prospectConvertPayloadCandidates($prospectId, $cpf, $customerName, $phone, $email) as $candidate) {
                try {
                    return $this->request('POST', $endpoint, [], $candidate);
                } catch (RuntimeException $e) {
                    $attemptErrors[] = sprintf('%s => %s', $endpoint, $e->getMessage());
                }
            }
        }

        $suffix = $attemptErrors !== []
            ? ' Tentativas: ' . implode(' | ', array_slice($attemptErrors, 0, 4))
            : '';

        throw new RuntimeException('Falha ao converter prospect em membro na EVO.' . $suffix);
    }

    /**
     * @return array<int,array{id:string,name:string,value:float,currency:string}>
     */
    public function listPlanOptions(?int $idBranch = null, bool $activeOnly = false, bool $onlineOnly = false): array
    {
        $errors = [];
        foreach ($this->planEndpoints() as $endpoint) {
            foreach ($this->planQueryCandidates($endpoint, $idBranch, $activeOnly, $onlineOnly) as $query) {
                try {
                    $payload = $this->request('GET', $endpoint, $query);
                    $plans = $this->parsePlanOptions($payload);
                    if ($plans !== []) {
                        return $plans;
                    }
                } catch (RuntimeException $e) {
                    $queryText = $query !== [] ? ('?' . http_build_query($query)) : '';
                    $errors[] = $endpoint . $queryText . ': ' . $e->getMessage();
                }
            }
        }

        if ($errors !== []) {
            throw new RuntimeException('Nao foi possivel carregar planos da EVO. ' . implode(' | ', array_slice($errors, 0, 3)));
        }

        return [];
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): array
    {
        $url = $this->buildUrl($path, $query);
        $headerVariants = $this->buildHeaderVariants($jsonBody !== null);
        $lastError = '';

        foreach ($headerVariants as $variantIndex => $requestHeaders) {
            $attempt = 0;

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

                if ($jsonBody !== null) {
                    $encoded = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($encoded === false) {
                        throw new RuntimeException('Payload JSON invalido para EVO.');
                    }
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
                }

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
                        'variant' => $variantIndex + 1,
                        'method' => $method,
                        'url' => $url,
                        'request_headers' => $this->redactHeadersForLog($requestHeaders),
                        'error' => $lastError,
                    ]);

                    if ($attempt <= $this->maxRetries) {
                        usleep(200000);
                        continue;
                    }

                    break;
                }

                $responseBody = is_string($rawResponse) ? $rawResponse : '';
                $this->writeLog([
                    'level' => 'info',
                    'type' => 'evo_request',
                    'attempt' => $attempt,
                    'variant' => $variantIndex + 1,
                    'method' => $method,
                    'url' => $url,
                    'request_headers' => $this->redactHeadersForLog($requestHeaders),
                    'http_code' => $httpCode,
                    'response' => $this->safeLogResponse($responseBody),
                    'request_body' => $jsonBody ?? null,
                ]);

                if ($httpCode >= 500 && $attempt <= $this->maxRetries) {
                    usleep(200000);
                    continue;
                }

                if ($httpCode === 401 || $httpCode === 403) {
                    $lastError = 'EVO HTTP ' . $httpCode . ': ' . $this->extractErrorMessage($responseBody);
                    break;
                }

                if ($httpCode === 405) {
                    $lastError = 'EVO HTTP 405: ' . $this->extractErrorMessage($responseBody);
                    if (!$this->headersUseBasicAuth($requestHeaders)) {
                        // Forca tentativa com Basic quando a primeira resposta vier 405.
                        break;
                    }
                    throw new RuntimeException($lastError);
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
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Falha inesperada ao chamar EVO.');
    }
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
     * @return array<int,array<int,string>>
     */
    private function buildHeaderVariants(bool $withJsonBody = false): array
    {
        $mode = strtolower(trim($this->authMode));
        if ($mode === 'auto') {
            $modes = ['bearer', 'basic'];
        } elseif ($mode === 'basic') {
            $modes = ['basic', 'bearer'];
        } else {
            $modes = ['bearer', 'basic'];
        }

        $variants = [];
        foreach ($modes as $authMode) {
            $headers = $this->buildHeaders($withJsonBody, $authMode);
            if ($headers !== []) {
                $variants[] = $headers;
            }
        }

        return $variants !== [] ? $variants : [$this->buildHeaders($withJsonBody, 'bearer')];
    }

    /**
     * @return string[]
     */
    private function buildHeaders(bool $withJsonBody = false, string $authMode = 'bearer'): array
    {
        $headers = [
            'Accept: application/json',
        ];
        if ($withJsonBody) {
            $headers[] = 'Content-Type: application/json';
        }

        if ($authMode === 'basic') {
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
        if ($this->proRequestHeaderName !== '' && $this->proRequestHeaderValue !== '') {
            $headers[] = $this->proRequestHeaderName . ': ' . $this->proRequestHeaderValue;
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,string>
     */
    private function normalizeQueryParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            if (is_bool($value)) {
                $normalized[(string) $key] = $value ? 'true' : 'false';
                continue;
            }
            $normalized[(string) $key] = (string) $value;
        }

        return $normalized;
    }

    /**
     * @param string[] $headers
     */
    private function headersUseBasicAuth(array $headers): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Authorization: Basic ') === 0) {
                return true;
            }
        }

        return false;
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
            $normalized = $this->normalizePositiveId($payload['idMember']);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        if (isset($payload['idCliente']) && is_scalar($payload['idCliente'])) {
            $normalized = $this->normalizePositiveId($payload['idCliente']);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $items = $payload['items'] ?? $payload['data'] ?? $payload;
        if (is_array($items)) {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (isset($row['idMember']) && is_scalar($row['idMember'])) {
                    $normalized = $this->normalizePositiveId($row['idMember']);
                    if ($normalized !== null) {
                        return $normalized;
                    }
                }
                if (isset($row['idCliente']) && is_scalar($row['idCliente'])) {
                    $normalized = $this->normalizePositiveId($row['idCliente']);
                    if ($normalized !== null) {
                        return $normalized;
                    }
                }
            }
        }

        return '';
    }

    private function normalizePositiveId(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        $numeric = (int) $digits;
        if ($numeric <= 0) {
            return null;
        }

        return (string) $numeric;
    }

    private function extractIdMemberByDocument(array $payload, string $document): ?string
    {
        $rows = $payload['items'] ?? $payload['data'] ?? [];
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowDocument = (string) ($row['document'] ?? $row['cpf'] ?? '');
            $rowDocument = preg_replace('/\D+/', '', $rowDocument) ?? '';
            if ($rowDocument !== '' && $rowDocument !== $document) {
                continue;
            }

            $id = $this->normalizePositiveId($row['idMember'] ?? $row['idCliente'] ?? null);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function extractProspectId(array $payload): string
    {
        $candidateKeys = ['idProspect', 'idOpportunity', 'idLead', 'id'];
        foreach ($candidateKeys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $normalized = $this->normalizePositiveId($payload[$key]);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        $items = $payload['items'] ?? $payload['data'] ?? [];
        if (is_array($items)) {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($candidateKeys as $key) {
                    if (isset($row[$key]) && is_scalar($row[$key])) {
                        $normalized = $this->normalizePositiveId($row[$key]);
                        if ($normalized !== null) {
                            return $normalized;
                        }
                    }
                }
            }
        }

        throw new RuntimeException('Resposta de cadastro de prospect sem idProspect/idOpportunity.');
    }

    /**
     * @return string[]
     */
    private function memberCreateEndpoints(): array
    {
        $raw = trim($this->env('EVO_MEMBER_CREATE_ENDPOINTS', '/api/v1/member,/api/v2/member,/api/v2/members,/api/v1/members'));
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');

        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = '/' . ltrim($part, '/');
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : ['/api/v2/member', '/api/v1/members'];
    }

    /**
     * @return string[]
     */
    private function prospectCreateEndpoints(): array
    {
        $raw = trim($this->env('EVO_PROSPECT_CREATE_ENDPOINTS', '/api/v1/prospect,/api/v1/prospects,/api/v2/prospect,/api/v2/prospects,/api/v1/opportunity,/api/v1/opportunities'));
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');

        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = '/' . ltrim($part, '/');
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : ['/api/v1/prospect'];
    }

    /**
     * @return string[]
     */
    private function prospectConvertEndpoints(): array
    {
        $raw = trim($this->env('EVO_PROSPECT_CONVERT_ENDPOINTS', '/api/v1/prospect/convert,/api/v1/prospects/convert,/api/v1/opportunity/convert,/api/v1/opportunities/convert,/api/v1/opportunity/turn-into-member,/api/v1/prospect/turn-into-member'));
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');

        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = '/' . ltrim($part, '/');
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : ['/api/v1/prospect/convert'];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function memberPayloadCandidates(string $cpf, string $customerName, string $phone, string $email): array
    {
        $cleanPhone = preg_replace('/\D+/', '', $phone) ?? '';
        $emailValue = $email !== '' ? $email : sprintf('sem-email-%s@evokore.local', $cpf);

        return [
            [
                'name' => $customerName,
                'document' => $cpf,
                'email' => $emailValue,
                'phone' => $cleanPhone,
                'cellPhone' => $cleanPhone,
            ],
            [
                'nameMember' => $customerName,
                'cpf' => $cpf,
                'email' => $emailValue,
                'phone' => $cleanPhone,
                'cellPhone' => $cleanPhone,
            ],
            [
                'name' => $customerName,
                'cpf' => $cpf,
                'email' => $emailValue,
                'phone' => $cleanPhone,
                'cellPhone' => $cleanPhone,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function prospectPayloadCandidates(string $cpf, string $customerName, string $phone, string $email): array
    {
        return [
            [
                'name' => $customerName,
                'document' => $cpf,
                'email' => $email,
                'phone' => $phone,
            ],
            [
                'name' => $customerName,
                'cpf' => $cpf,
                'email' => $email,
                'phone' => $phone,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */private function prospectConvertPayloadCandidates(string $prospectId, string $cpf, string $customerName, string $phone, string $email): array
    {
        return [
            ['IdProspect' => (int) $prospectId],
            ['idProspect' => (int) $prospectId],
            [
                'IdProspect' => (int) $prospectId,
                'Name' => $customerName,
                'Document' => $cpf,
                'Email' => $email,
                'Phone' => $phone,
            ],
        ];
    }

    /**
     * @return string[]
     */private function planEndpoints(): array
    {
        $raw = trim($this->env('EVO_PLANS_ENDPOINTS', '/api/v2/membership,/api/v1/membership,/api/v1/memberships,/api/v1/sales/plans,/api/v1/plans'));
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');

        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = '/' . ltrim($part, '/');
        }

        if ($normalized === []) {
            $normalized = ['/api/v2/membership', '/api/v1/membership'];
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function parsePlanOptions(array $payload): array
    {
        $rows = $payload['items'] ?? $payload['data'] ?? $payload['results'] ?? $payload['list'] ?? $payload['lista'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $plans = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $idRaw = $row['idMembership'] ?? $row['membershipId'] ?? $row['idPlan'] ?? $row['id'] ?? null;
            $id = $this->normalizePositiveId($idRaw);
            if ($id === null) {
                continue;
            }

            $name = trim((string) ($row['nameMembership'] ?? $row['name'] ?? $row['description'] ?? ''));
            if ($name === '') {
                continue;
            }

            $regularValue = (float) ($row['value'] ?? $row['price'] ?? $row['amount'] ?? 0);
            $promotionalValue = (float) ($row['valuePromotionalPeriod'] ?? $row['promotionalValue'] ?? $row['valuePromotion'] ?? 0);
            $isPromotional = $promotionalValue > 0;
            $effectiveValue = $promotionalValue > 0 ? $promotionalValue : $regularValue;
            $status = isset($row['status']) ? trim((string) $row['status']) : null;

            $effectiveRounded = round($effectiveValue, 2);
            $regularRounded = round($regularValue, 2);
            $promotionalRounded = round($promotionalValue, 2);

            $plans[] = [
                'id' => $id,
                'name' => $name,
                'value' => $effectiveRounded,
                'regular_value' => $regularRounded,
                'promotional_value' => $promotionalRounded,
                'currency' => strtoupper(trim((string) ($row['currency'] ?? 'BRL'))),
                'is_active' => $this->extractPlanActiveFlag($row),
                'is_online' => $this->extractPlanOnlineFlag($row),
                'status' => $status !== '' ? $status : null,
                // Campos novos para compatibilidade com fluxo Blip/n8n de promocoes.
                'is_promotional' => $isPromotional,
                'value_label' => $this->formatMoneyLabel($effectiveRounded),
                'regular_value_label' => $this->formatMoneyLabel($regularRounded),
                'promotional_value_label' => $promotionalRounded > 0
                    ? $this->formatMoneyLabel($promotionalRounded)
                    : null,
                'promo_message' => $isPromotional
                    ? $this->buildPromotionalMessage($promotionalRounded, $regularRounded)
                    : null,
                'promo' => [
                    'is_promo' => $isPromotional,
                    'first_period_value' => $promotionalRounded > 0 ? $promotionalRounded : null,
                    'regular_value_after_period' => $regularRounded > 0 ? $regularRounded : null,
                    'period_label' => $isPromotional ? '1o mes' : null,
                    'message' => $isPromotional
                        ? $this->buildPromotionalMessage($promotionalRounded, $regularRounded)
                        : null,
                ],
                'months_promotional_period' => (int) ($row['monthsPromotionalPeriod'] ?? 0),
                'days_promotional_period' => (int) ($row['daysPromotionalPeriod'] ?? 0),
                'online_sales_observations' => $this->extractOnlineSalesObservation($row),
                'differentials' => $this->extractDifferentials($row),
                'benefits' => $this->extractPlanBenefits($row),
                'url_sale' => isset($row['urlSale']) ? trim((string) $row['urlSale']) : null,
                'external_sale_available' => $this->normalizeBoolLike($row['externalSaleAvailable'] ?? null),
            ];
        }

        return $plans;
    }

    private function formatMoneyLabel(float $amount): string
    {
        return 'R$ ' . number_format($amount, 2, '.', '');
    }

    private function buildPromotionalMessage(float $promoValue, float $regularValue): string
    {
        return sprintf(
            'Promocao: R$ %.2f no 1o mes. Apos esse periodo, a mensalidade sera de R$ %.2f.',
            $promoValue,
            $regularValue
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private function extractDifferentials(array $row): array
    {
        $raw = $row['differentials'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $text = trim($entry);
                if ($text !== '') {
                    $items[] = $text;
                }
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }

            foreach (['description', 'name', 'title', 'differential', 'text'] as $key) {
                if (!isset($entry[$key])) {
                    continue;
                }
                $text = trim((string) $entry[$key]);
                if ($text !== '') {
                    $items[] = $text;
                    break;
                }
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractOnlineSalesObservation(array $row): ?string
    {
        foreach (['onlineSalesObservations', 'onlineSalesObservation', 'salesObservation', 'observation'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $text = trim((string) $row[$key]);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private function extractPlanBenefits(array $row): array
    {
        $benefits = $this->extractDifferentials($row);
        $observation = $this->extractOnlineSalesObservation($row);
        if ($observation === null || $observation === '') {
            return $benefits;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $observation);
        $lines = explode("\n", $normalized);
        foreach ($lines as $line) {
            $clean = trim($line);
            $clean = trim($clean, " \t\n\r\0\x0B-•");
            if ($clean === '') {
                continue;
            }

            // Evita duplicar linhas de promocao e valores no bloco de beneficios
            if (stripos($clean, 'promoc') !== false || stripos($clean, 'mensalidade') !== false) {
                continue;
            }

            $benefits[] = $clean;
        }

        return array_values(array_unique($benefits));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractPlanActiveFlag(array $row): ?bool
    {
        foreach (['isActive', 'active', 'flActive', 'is_active'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            return $this->normalizeBoolLike($row[$key]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractPlanOnlineFlag(array $row): ?bool
    {
        $candidates = [
            'isOnlineSale',
            'onlineSale',
            'allowOnlineSale',
            'flOnlineSale',
            'online',
            'sellOnline',
            'isOnline',
            'onlineSaleEnabled',
        ];
        foreach ($candidates as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            return $this->normalizeBoolLike($row[$key]);
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeBoolLike($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtoupper(trim($value));
            if ($normalized === '') {
                return null;
            }
            if (in_array($normalized, ['1', 'TRUE', 'SIM', 'YES', 'ATIVO', 'ACTIVE', 'ONLINE'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'FALSE', 'NAO', 'NÃO', 'NO', 'INATIVO', 'INACTIVE', 'OFFLINE'], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function planQueryCandidates(string $endpoint, ?int $idBranch, bool $activeOnly, bool $onlineOnly): array
    {
        $endpointPath = '/' . ltrim(explode('?', $endpoint, 2)[0], '/');

        if ($endpointPath === '/api/v2/membership') {
            $query = [
                'showAccessBranches' => 'false',
                'showOnlineSalesObservation' => 'true',
            ];
            if ($idBranch !== null && $idBranch > 0) {
                $query['idBranch'] = (string) $idBranch;
            }
            if ($activeOnly) {
                $query['active'] = 'true';
            }
            if ($onlineOnly) {
                $query['onlineOnly'] = 'true';
            }

            return [$query, []];
        }

        return [['take' => '200', 'skip' => '0'], []];
    }

    /**
     * @param array<string,string> $extraQuery
     * @param array<string,mixed>|null $jsonBody
     * @return array<string,mixed>
     */
    private function requestEndpoint(string $method, string $endpoint, ?array $jsonBody = null, array $extraQuery = []): array
    {
        [$path, $query] = $this->splitEndpointAndQuery($endpoint);
        if ($extraQuery !== []) {
            $query = array_merge($query, $extraQuery);
        }

        return $this->request($method, $path, $query, $jsonBody);
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function splitEndpointAndQuery(string $endpoint): array
    {
        $parts = explode('?', trim($endpoint), 2);
        $path = '/' . ltrim($parts[0] !== '' ? $parts[0] : '/', '/');
        $query = [];
        if (isset($parts[1]) && $parts[1] !== '') {
            parse_str($parts[1], $query);
            $query = array_map(static fn ($value): string => (string) $value, $query);
        }

        return [$path, $query];
    }

    /**
     * @return array<string,string>
     */
    private function defaultSaleQuery(string $endpoint): array
    {
        $showContractHtml = strtolower(trim((string) $this->env('EVO_SALE_SHOW_CONTRACT_HTML', 'false')));
        if (!in_array($showContractHtml, ['true', 'false'], true)) {
            $showContractHtml = 'false';
        }

        $path = '/' . ltrim(explode('?', $endpoint, 2)[0], '/');
        if ($path !== '/api/v2/sales') {
            return [];
        }

        if (str_contains($endpoint, 'showContractHTML=')) {
            return [];
        }

        return ['showContractHTML' => $showContractHtml];
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
            // erro de log nao deve quebrar fluxo principal
        }
    }

    private function shouldTryPaymentFallback(string $message): bool
    {
        $m = strtolower($message);
        $mNormalized = strtr($m, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);

        return str_contains($mNormalized, 'cartao de credito nao encontrado')
            || str_contains($mNormalized, 'pago apenas com cartao de credito')
            || str_contains($mNormalized, 'apenas com cartao de credito')
            || str_contains($mNormalized, 'esse contrato pode ser pago apenas com cartao de credito')
            || str_contains($mNormalized, 'pode ser pago apenas com')
            || str_contains($mNormalized, 'somente cartao')
            || str_contains($mNormalized, 'paid only with credit card')
            || str_contains($mNormalized, 'failure to generating bank slip')
            || str_contains($mNormalized, 'boleto');
    }

    /**
     * @return int[]
     */
    private function salePaymentFallbacks(int $currentPayment): array
    {
        $raw = trim($this->env('EVO_SALE_PAYMENT_FALLBACKS', '2,3,4'));
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '');

        $codes = [];
        foreach ($parts as $part) {
            $code = (int) $part;
            if ($code <= 0 || $code === $currentPayment) {
                continue;
            }
            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param array<string,mixed> $payload
     * @return string[]
     */
    private function extractScheduleTimeSlots(array $payload, string $targetDate): array
    {
        $slots = [];
        $this->collectTimeSlotsFromNode($payload, $targetDate, $slots);

        return array_values(array_unique($slots));
    }

    /**
     * @param mixed $node
     * @param string[] $slots
     */
    private function collectTimeSlotsFromNode(mixed $node, string $targetDate, array &$slots): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($this->isAssociativeArray($node)) {
            $this->collectTimeSlotFromRow($node, $targetDate, $slots);
        }

        foreach ($node as $value) {
            $this->collectTimeSlotsFromNode($value, $targetDate, $slots);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $slots
     */
    private function collectTimeSlotFromRow(array $row, string $targetDate, array &$slots): void
    {
        $dateTimeCandidates = [
            $row['startDate'] ?? null,
            $row['dateTime'] ?? null,
            $row['initialDate'] ?? null,
            $row['dtActivity'] ?? null,
            $row['start'] ?? null,
            $row['end'] ?? null,
        ];

        foreach ($dateTimeCandidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $normalized = $this->normalizeDateTimeCandidate((string) $candidate);
            if ($normalized === null || $normalized['date'] !== $targetDate) {
                continue;
            }

            $slots[] = $normalized['time'];
        }

        $dateCandidate = $row['activityDate'] ?? $row['startDate'] ?? $row['date'] ?? $row['dtActivity'] ?? null;
        $timeCandidate = $row['startTime'] ?? $row['time'] ?? $row['hour'] ?? $row['startHour'] ?? $row['hourStart'] ?? null;
        if (is_scalar($dateCandidate) && is_scalar($timeCandidate)) {
            $dateOnly = $this->normalizeDateOnlyCandidate((string) $dateCandidate);
            $timeOnly = $this->normalizeHourMinuteCandidate((string) $timeCandidate);
            if ($dateOnly === $targetDate && $timeOnly !== null) {
                $slots[] = $timeOnly;
            }
        }
    }

    /**
     * @param mixed $node
     * @param array<int,array<string,mixed>> $rows
     */
    private function collectScheduleRows(mixed $node, array &$rows): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($this->isAssociativeArray($node) && $this->looksLikeScheduleRow($node)) {
            $rows[] = $node;
        }

        foreach ($node as $value) {
            $this->collectScheduleRows($value, $rows);
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function looksLikeScheduleRow(array $row): bool
    {
        $candidates = ['activityDate', 'startDate', 'date', 'dtActivity', 'startTime', 'time', 'hour'];
        foreach ($candidates as $key) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractRowDate(array $row): ?string
    {
        $candidates = [
            $row['activityDate'] ?? null,
            $row['startDate'] ?? null,
            $row['date'] ?? null,
            $row['dtActivity'] ?? null,
            $row['dateTime'] ?? null,
            $row['initialDate'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $value = $this->normalizeDateOnlyCandidate((string) $candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractRowTime(array $row): ?string
    {
        $timeCandidates = [
            $row['startTime'] ?? null,
            $row['time'] ?? null,
            $row['hour'] ?? null,
            $row['startHour'] ?? null,
            $row['hourStart'] ?? null,
        ];
        foreach ($timeCandidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $time = $this->normalizeHourMinuteCandidate((string) $candidate);
            if ($time !== null) {
                return $time;
            }
        }

        $dateTimeCandidates = [
            $row['startDate'] ?? null,
            $row['dateTime'] ?? null,
            $row['initialDate'] ?? null,
            $row['dtActivity'] ?? null,
        ];
        foreach ($dateTimeCandidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $normalized = $this->normalizeDateTimeCandidate((string) $candidate);
            if ($normalized !== null) {
                return $normalized['time'];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractRowClassName(array $row): ?string
    {
        $candidates = [
            'activity',
            'activityName',
            'service',
            'serviceName',
            'className',
            'name',
            'description',
            'modalidade',
            'modality',
        ];
        foreach ($candidates as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractRowTeacherName(array $row): ?string
    {
        $candidates = [
            'teacher',
            'teacherName',
            'nameTeacher',
            'professor',
            'instructor',
            'employeeName',
            'staffName',
        ];
        foreach ($candidates as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function weekdayName(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        $map = [
            'Sunday' => 'domingo',
            'Monday' => 'segunda',
            'Tuesday' => 'terca',
            'Wednesday' => 'quarta',
            'Thursday' => 'quinta',
            'Friday' => 'sexta',
            'Saturday' => 'sabado',
        ];
        $en = date('l', $ts);

        return $map[$en] ?? strtolower($en);
    }

    private function normalizeDateOnlyCandidate(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
            return $m[1];
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @return array{date:string,time:string}|null
     */
    private function normalizeDateTimeCandidate(string $raw): ?array
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        // Evita transformar "YYYY-MM-DD" em "00:00" (falso horario disponivel)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return null;
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2})/', $value, $m) === 1) {
            return [
                'date' => $m[1],
                'time' => $m[2],
            ];
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return [
            'date' => date('Y-m-d', $timestamp),
            'time' => date('H:i', $timestamp),
        ];
    }

    private function normalizeHourMinuteCandidate(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{2}:\d{2})/', $value, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/\b(\d{2}:\d{2})\b/', $value, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @return string[]
     */
    /**
     * @return string[]
     */
    private function trialCreateEndpoints(): array
    {
        $raw = trim($this->env('EVO_TRIAL_ENDPOINTS', '/api/v1/activities/schedule/experimental-class'));
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');

        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = '/' . ltrim($part, '/');
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : ['/api/v1/activities/schedule/experimental-class'];
    }

    private function sanitizeHourMinute(string $value, string $field): string
    {
        $time = trim($value);
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new RuntimeException($field . ' invalido. Use HH:mm.');
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new RuntimeException($field . ' invalido. Use HH:mm.');
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
    private function isTrialEndpointUnavailable(array $errors): bool
    {
        if ($errors === []) {
            return false;
        }

        foreach ($errors as $error) {
            $m = strtolower($error);
            $isUnavailable = str_contains($m, 'evo http 404') || str_contains($m, 'evo http 405');
            if (!$isUnavailable) {
                return false;
            }
        }

        return true;
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

    private function normalizeHeaderName(string $name): string
    {
        $value = trim($name);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9-]+$/', $value) !== 1) {
            return '';
        }

        return $value;
    }
}














