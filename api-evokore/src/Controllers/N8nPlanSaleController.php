<?php
declare(strict_types=1);

namespace EvoKore\Controllers;

use EvoKore\Services\EvoService;
use EvoKore\Support\UnitContextResolver;
use PDO;
use RuntimeException;
use Throwable;

final class N8nPlanSaleController extends N8nFlowController
{
    public function handle(): void
    {
        try {
            $context = $this->requireUnitContext();
            $payload = $this->getJsonBody();
            $normalized = $this->normalizePayload($payload);

            $db = $this->db();
            if ($db === null) {
                throw new RuntimeException('Database unavailable.');
            }

            $this->ensureTables($db);
            $resolver = new UnitContextResolver($db, (array) ($this->app['env'] ?? []));
            $credential = $resolver->resolveEvoCredentialByUnit((int) $context['unit_id']);

            $evoService = new EvoService([
                'base_url' => (string) ($this->app['env']['EVO_BASE_URL'] ?? ''),
                'dns' => $credential['dns'],
                'token' => $credential['token'],
                'api_key' => $credential['token'],
                'auth_mode' => (string) ($this->app['env']['EVO_AUTH_MODE'] ?? 'basic'),
                'dns_header_name' => (string) ($this->app['env']['EVO_DNS_HEADER_NAME'] ?? 'DNS'),
                'timeout_seconds' => (int) ($this->app['env']['EVO_TIMEOUT_SECONDS'] ?? 10),
                'max_retries' => (int) ($this->app['env']['EVO_MAX_RETRIES'] ?? 2),
                'log_file' => (string) ($this->app['paths']['base'] ?? __DIR__ . '/../../') . '/logs/evo-sales.log',
            ]);

            $idBranch = (int) ($normalized['evo_payload']['idBranch'] ?? (int) ($this->app['env']['EVO_DEFAULT_BRANCH_ID'] ?? 1));
            if ($idBranch <= 0) {
                $idBranch = 1;
            }

            $onlinePlan = $this->resolveOnlinePlan(
                $evoService,
                $idBranch,
                isset($normalized['plan_id']) ? (int) $normalized['plan_id'] : null,
                (string) $normalized['plan_name']
            );

            $normalized['plan_id'] = (int) ($onlinePlan['id'] ?? 0);
            $normalized['plan_name'] = (string) ($onlinePlan['name'] ?? $normalized['plan_name']);
            $normalized['plan_value'] = round((float) ($onlinePlan['value'] ?? $normalized['plan_value']), 2);

            // Fluxo equivalente ao Blip para venda online: prospect + payment=6 + idProspect.
            $prospectResponse = $evoService->createProspect([
                'cpf' => $normalized['cpf'],
                'customer_name' => $normalized['customer_name'],
                'phone' => $normalized['phone'],
                'email' => $normalized['email'],
            ]);
            $prospectId = $this->extractProspectId($prospectResponse);
            if ($prospectId <= 0) {
                throw new RuntimeException('Nao foi possivel obter idProspect para venda online.');
            }

            $evoPayload = [
                'idBranch' => $idBranch,
                'payment' => 6,
                'idMembership' => (int) $normalized['plan_id'],
                'membershipId' => (int) $normalized['plan_id'],
                'idProspect' => $prospectId,
                'totalInstallments' => isset($normalized['evo_payload']['totalInstallments']) ? (int) $normalized['evo_payload']['totalInstallments'] : 1,
                'memberData' => [
                    'idMember' => 0,
                    'document' => (string) $normalized['cpf'],
                    'name' => (string) $normalized['customer_name'],
                    'email' => (string) $normalized['email'],
                    'phone' => (string) $normalized['phone'],
                ],
                'plan_name' => (string) $normalized['plan_name'],
                'plan_value' => (float) $normalized['plan_value'],
                'meta' => $normalized['evo_payload']['meta'] ?? null,
            ];

            $evoResponse = $evoService->createPlanSale($evoPayload);
            $paymentLink = $this->extractPaymentLink($evoResponse);

            $normalized['evo_payload'] = $evoPayload;
            $normalized['status'] = 'SENT';
            $saleId = $this->persistSale($db, $context, $normalized, $evoResponse, $paymentLink);

            $this->json(201, [
                'data' => [
                    'sale_id' => $saleId,
                    'unit_id' => (int) $context['unit_id'],
                    'unit_name' => (string) $context['unit_name'],
                    'cpf' => $normalized['cpf'],
                    'customer_name' => $normalized['customer_name'],
                    'plan_name' => $normalized['plan_name'],
                    'plan_value' => $normalized['plan_value'],
                    'payment_link' => $paymentLink,
                    'evo_sale_id' => $this->extractEvoSaleId($evoResponse),
                    'evo_member_id' => $this->extractEvoMemberId($evoResponse),
                    'id_prospect' => $prospectId,
                    'message' => $paymentLink !== null && $paymentLink !== ''
                        ? 'Venda online criada e link de pagamento retornado.'
                        : 'Venda online criada na EVO, sem link de pagamento no retorno.',
                ],
            ]);
        } catch (RuntimeException $e) {
            $this->json(400, ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->json(500, ['error' => 'Internal Server Error', 'detail' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $cpf = preg_replace('/\D+/', '', (string) ($payload['cpf'] ?? '')) ?? '';
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $planId = trim((string) ($payload['plan_id'] ?? ''));
        $planName = trim((string) ($payload['plan_name'] ?? ''));
        $planValue = (float) ($payload['plan_value'] ?? 0);

        if (strlen($cpf) !== 11) {
            throw new RuntimeException('CPF invalido (11 digitos).');
        }
        if ($customerName === '') {
            throw new RuntimeException('customer_name e obrigatorio.');
        }
        if ($planName === '' && $planId === '') {
            throw new RuntimeException('Informe plan_id ou plan_name.');
        }
        if ($planValue <= 0) {
            throw new RuntimeException('plan_value deve ser maior que zero.');
        }

        $normalized = [
            'cpf' => $cpf,
            'customer_name' => $customerName,
            'phone' => $phone,
            'email' => $email,
            'plan_name' => $planName,
            'plan_value' => round($planValue, 2),
            'status' => strtoupper(trim((string) ($payload['status'] ?? 'PENDING'))),
            'evo_payload' => [
                'idBranch' => isset($payload['id_branch']) ? (int) $payload['id_branch'] : (int) ($this->app['env']['EVO_DEFAULT_BRANCH_ID'] ?? 1),
                'totalInstallments' => isset($payload['total_installments']) ? (int) $payload['total_installments'] : 1,
                'meta' => $payload['meta'] ?? null,
            ],
        ];

        if ($planId !== '') {
            $normalized['plan_id'] = (int) $planId;
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveOnlinePlan(EvoService $evoService, int $idBranch, ?int $planId, string $planName): array
    {
        $plans = $evoService->listPlanOptions($idBranch, true, true);
        if ($plans === []) {
            throw new RuntimeException('Nenhum plano online ativo disponivel para a unidade informada.');
        }

        if ($planId !== null && $planId > 0) {
            foreach ($plans as $plan) {
                if ((int) ($plan['id'] ?? 0) === $planId) {
                    return $plan;
                }
            }
            throw new RuntimeException('plan_id informado nao e online/ativo para esta unidade.');
        }

        $wanted = mb_strtoupper(trim($planName), 'UTF-8');
        if ($wanted !== '') {
            foreach ($plans as $plan) {
                $candidate = mb_strtoupper(trim((string) ($plan['name'] ?? '')), 'UTF-8');
                if ($candidate !== '' && $candidate === $wanted) {
                    return $plan;
                }
            }
            foreach ($plans as $plan) {
                $candidate = mb_strtoupper(trim((string) ($plan['name'] ?? '')), 'UTF-8');
                if ($candidate !== '' && str_contains($candidate, $wanted)) {
                    return $plan;
                }
            }
        }

        throw new RuntimeException('Plano informado nao e online/ativo para esta unidade. Informe um plan_id online valido.');
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractProspectId(array $response): int
    {
        $roots = [$response];
        if (isset($response['data']) && is_array($response['data'])) {
            $roots[] = $response['data'];
        }

        $keys = ['idProspect', 'id_prospect', 'prospectId', 'idOpportunity', 'id_opportunity'];
        foreach ($roots as $root) {
            foreach ($keys as $key) {
                if (isset($root[$key])) {
                    $value = (int) $root[$key];
                    if ($value > 0) {
                        return $value;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractPaymentLink(array $response): ?string
    {
        $roots = [$response];
        if (isset($response['data']) && is_array($response['data'])) {
            $roots[] = $response['data'];
        }

        $directKeys = [
            'checkoutLinkFullDebt',
            'payment_link',
            'paymentLink',
            'link',
            'checkoutLink',
            'linkCheckout',
            'urlEnvioCheckout',
            'linkBoleto',
        ];

        foreach ($roots as $root) {
            foreach ($directKeys as $key) {
                if (isset($root[$key]) && is_string($root[$key]) && trim($root[$key]) !== '') {
                    return trim((string) $root[$key]);
                }
            }

            if (isset($root['clienteContratos']) && is_array($root['clienteContratos'])) {
                foreach ($root['clienteContratos'] as $contract) {
                    if (!is_array($contract)) {
                        continue;
                    }
                    $contractKeys = ['linkAceiteContrato', 'linkSolicitacaoAssinaturaContratoFuncionario'];
                    foreach ($contractKeys as $key) {
                        if (isset($contract[$key]) && is_string($contract[$key]) && trim($contract[$key]) !== '') {
                            return trim((string) $contract[$key]);
                        }
                    }
                }
            }

            if (isset($root['configuracaoPayU']) && is_array($root['configuracaoPayU'])) {
                $payuKeys = ['responseUrl', 'confirmationUrl'];
                foreach ($payuKeys as $key) {
                    if (isset($root['configuracaoPayU'][$key]) && is_string($root['configuracaoPayU'][$key]) && trim($root['configuracaoPayU'][$key]) !== '') {
                        return trim((string) $root['configuracaoPayU'][$key]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractEvoSaleId(array $response): ?int
    {
        $roots = [$response];
        if (isset($response['data']) && is_array($response['data'])) {
            $roots[] = $response['data'];
        }

        foreach ($roots as $root) {
            if (isset($root['idVenda'])) {
                $value = (int) $root['idVenda'];
                if ($value > 0) {
                    return $value;
                }
            }
            if (isset($root['idSale'])) {
                $value = (int) $root['idSale'];
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractEvoMemberId(array $response): ?int
    {
        $roots = [$response];
        if (isset($response['data']) && is_array($response['data'])) {
            $roots[] = $response['data'];
        }

        foreach ($roots as $root) {
            if (isset($root['idCliente'])) {
                $value = (int) $root['idCliente'];
                if ($value > 0) {
                    return $value;
                }
            }
            if (isset($root['idMember'])) {
                $value = (int) $root['idMember'];
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function persistSale(
        PDO $db,
        array $context,
        array $normalized,
        array $evoResponse,
        ?string $paymentLink
    ): int {
        $evoSaleId = $this->extractEvoSaleId($evoResponse);
        $evoMemberId = $this->extractEvoMemberId($evoResponse);
        $paymentReference = $this->extractPaymentReference($evoResponse);

        $stmt = $db->prepare(
            'INSERT INTO plan_sales
                (client_id, unit_id, cpf, customer_name, phone, email, plan_name, plan_value, status, payment_link, evo_sale_id, evo_member_id, payment_reference, evo_payload_json, evo_response_json, created_at, updated_at)
             VALUES
                (:client_id, :unit_id, :cpf, :customer_name, :phone, :email, :plan_name, :plan_value, :status, :payment_link, :evo_sale_id, :evo_member_id, :payment_reference, :evo_payload_json, :evo_response_json, NOW(), NOW())'
        );

        $stmt->execute([
            ':client_id' => (int) $context['client_id'],
            ':unit_id' => (int) $context['unit_id'],
            ':cpf' => (string) $normalized['cpf'],
            ':customer_name' => (string) $normalized['customer_name'],
            ':phone' => (string) $normalized['phone'],
            ':email' => (string) $normalized['email'],
            ':plan_name' => (string) $normalized['plan_name'],
            ':plan_value' => (float) $normalized['plan_value'],
            ':status' => (string) $normalized['status'],
            ':payment_link' => $paymentLink,
            ':evo_sale_id' => $evoSaleId,
            ':evo_member_id' => $evoMemberId,
            ':payment_reference' => $paymentReference,
            ':evo_payload_json' => json_encode($normalized['evo_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':evo_response_json' => json_encode($evoResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $db->lastInsertId();
    }

    private function ensureTables(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS plan_sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                unit_id INT NOT NULL,
                cpf VARCHAR(14) NOT NULL,
                customer_name VARCHAR(255) NOT NULL,
                phone VARCHAR(40) NULL,
                email VARCHAR(255) NULL,
                plan_name VARCHAR(255) NOT NULL,
                plan_value DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT "PENDING",
                paid_value DECIMAL(12,2) NULL,
                paid_at DATETIME NULL,
                status_note VARCHAR(255) NULL,
                payment_link TEXT NULL,
                evo_sale_id BIGINT NULL,
                evo_member_id BIGINT NULL,
                payment_reference VARCHAR(100) NULL,
                last_webhook_at DATETIME NULL,
                last_webhook_payload_json JSON NULL,
                evo_payload_json JSON NULL,
                evo_response_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_plan_sales_dates (created_at),
                INDEX idx_plan_sales_client (client_id),
                INDEX idx_plan_sales_unit (unit_id),
                INDEX idx_plan_sales_status (status),
                INDEX idx_plan_sales_evo_sale_id (evo_sale_id),
                INDEX idx_plan_sales_evo_member_id (evo_member_id),
                INDEX idx_plan_sales_payment_reference (payment_reference)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS paid_value DECIMAL(12,2) NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS status_note VARCHAR(255) NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS evo_sale_id BIGINT NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS evo_member_id BIGINT NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS last_webhook_at DATETIME NULL');
        $db->exec('ALTER TABLE plan_sales ADD COLUMN IF NOT EXISTS last_webhook_payload_json JSON NULL');
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractPaymentReference(array $response): ?string
    {
        $roots = [$response];
        if (isset($response['data']) && is_array($response['data'])) {
            $roots[] = $response['data'];
        }

        foreach ($roots as $root) {
            foreach (['referenceCode', 'reference', 'paymentReference', 'idExternoBoleto'] as $key) {
                if (isset($root[$key]) && is_scalar($root[$key])) {
                    $value = trim((string) $root[$key]);
                    if ($value !== '') {
                        return substr($value, 0, 100);
                    }
                }
            }

            if (isset($root['configuracaoPayU']) && is_array($root['configuracaoPayU'])) {
                $payu = $root['configuracaoPayU'];
                foreach (['referenceCode', 'referenceCodePayU'] as $key) {
                    if (isset($payu[$key]) && is_scalar($payu[$key])) {
                        $value = trim((string) $payu[$key]);
                        if ($value !== '') {
                            return substr($value, 0, 100);
                        }
                    }
                }
            }
        }

        return null;
    }
}
