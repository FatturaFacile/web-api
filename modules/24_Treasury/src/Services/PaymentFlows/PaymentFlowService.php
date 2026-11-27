<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows;

use App\Exceptions\Pain001Exception;
use App\Models\Tenant;
use App\Services\Banking\Iso20022\Pain001CategoryPurpose;
use App\Services\Banking\Iso20022\Pain001ChargeBearer;
use App\Services\Banking\Iso20022\Pain001Message;
use App\Services\Banking\Iso20022\Pain001Payment;
use App\Services\Banking\Iso20022\Pain001SchemaValidator;
use App\Services\Banking\Iso20022\Pain001XmlBuilder;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;

/**
 * Generates the outgoing payment files of the treasury workspace: Swiss pain.001.001.09 (IBAN and QR-bill payments),
 * SEPA pain.001.001.03 and the CBI bonifico flow. A file is produced only when every business rule passes and the
 * result validates against the bundled ISO 20022 schema; otherwise a ValidationException lists every problem.
 */
final readonly class PaymentFlowService
{
    public function __construct(
        private PaymentFlowRules $rules,
        private DuplicatePaymentGuard $duplicates,
        private Pain001XmlBuilder $builder,
        private Pain001SchemaValidator $schema,
        private CbiInitiatorDecorator $cbi,
        private PaymentFactoryServiceInterface $factory,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Builds and validates the file without storing anything (preview / dry run).
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    public function generate(PaymentFlow $flow, string $bankAccountId, string $executionDate, array $lines, ?string $cuc = null, ?string $currency = null, ?Pain001CategoryPurpose $categoryPurpose = null, ?Pain001ChargeBearer $chargeBearer = null): GeneratedPaymentFlow
    {
        $account = BankAccount::query()->findOrFail($bankAccountId);
        $lines = $this->normalize($lines, strtoupper($currency ?? (string) ($account->currency ?? $flow->currencies()[0])));
        $cuc = $cuc !== null ? strtoupper(trim($cuc)) : null;

        $this->assertNoIssues([
            ...$this->rules->check($flow, (string) $account->iban, $lines, $cuc),
            ...$this->duplicates->check($lines),
        ]);

        $tenantId = (string) $this->tenantContext->getTenantId();
        $debtorName = (string) (Tenant::query()->whereKey($tenantId)->value('legal_name') ?: $account->bank_name);
        $messageId = 'MSG-'.date('YmdHis').'-'.strtoupper(Str::random(6));
        $payments = array_map(static fn (array $line): Pain001Payment => new Pain001Payment(
            creditorName: (string) $line['recipient_name'],
            creditorIban: (string) $line['recipient_iban'],
            amount: (float) $line['amount'],
            currency: (string) $line['currency'],
            endToEndId: (string) $line['end_to_end_id'],
            instructionId: (string) $line['instruction_id'],
            creditorBic: ($line['recipient_bic'] ?? null) ?: null,
            reference: ($line['structured_creditor_ref'] ?? null) ?: null,
            remittanceText: ($line['remittance_reference'] ?? null) ?: null,
        ), $lines);

        try {
            $xml = $this->builder->build(new Pain001Message(
                messageId: $messageId,
                createdAt: CarbonImmutable::now(),
                initiatorName: $debtorName,
                paymentInfoId: 'PMT-'.strtoupper(Str::random(8)),
                executionDate: CarbonImmutable::parse($executionDate),
                debtorName: $debtorName,
                debtorIban: (string) $account->iban,
                debtorBic: $account->bic_swift !== null ? (string) $account->bic_swift : null,
                payments: $payments,
                categoryPurpose: $categoryPurpose,
                chargeBearer: $chargeBearer,
            ), $flow->version());

            if ($flow->requiresCuc()) {
                $xml = $this->cbi->apply($xml, (string) $cuc);
            }

            $this->schema->validate($xml, $flow->version());
        } catch (Pain001Exception $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $sum = '0.00';

        foreach ($lines as $line) {
            $sum = bcadd($sum, bcadd((string) $line['amount'], '0', 2), 2);
        }

        return new GeneratedPaymentFlow($flow, $xml, hash('sha256', $xml), $messageId, count($lines), $sum, (string) $lines[0]['currency'], $flow->value.'_'.date('Ymd_His').'.xml');
    }

    /**
     * Stores the validated payment as a draft batch that follows the normal approval, export and bank confirmation
     * workflow. The file given to the bank is produced again, identically, when the batch is exported.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    public function createBatch(PaymentFlow $flow, string $bankAccountId, string $executionDate, array $lines, ?string $cuc, ?string $currency, string $userId, ?Pain001CategoryPurpose $categoryPurpose = null, ?Pain001ChargeBearer $chargeBearer = null): PaymentBatch
    {
        $generated = $this->generate($flow, $bankAccountId, $executionDate, $lines, $cuc, $currency, $categoryPurpose, $chargeBearer);
        $tenantId = (string) $this->tenantContext->getTenantId();
        $normalized = $this->normalize($lines, $generated->currency);

        $batch = $this->factory->createBatch([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'bank_account_id' => $bankAccountId,
            'execution_date' => $executionDate,
            'currency' => $generated->currency,
            'format' => $flow->batchFormat(),
            'pain_version' => $flow->version()->value,
            'payment_method' => $flow->paymentMethod(),
            'category_purpose' => $categoryPurpose?->value,
            'charge_bearer' => $chargeBearer?->value,
        ], $normalized, $userId);

        if ($flow->requiresCuc()) {
            $batch->update(['cbi_cuc' => strtoupper((string) $cuc)]);
        }

        $batch->update(['xml_sha256' => hash('sha256', $this->factory->generatePain001Xml((string) $batch->id))]);

        return $batch->refresh()->load('lines');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function normalize(array $lines, string $currency): array
    {
        return array_map(static function (array $line) use ($currency): array {
            $line['recipient_iban'] = strtoupper((string) preg_replace('/[\s-]+/', '', (string) ($line['recipient_iban'] ?? '')));
            $line['currency'] = strtoupper((string) ($line['currency'] ?? $currency));
            $line['amount'] = isset($line['amount']) ? bcadd((string) $line['amount'], '0', 2) : '0.00';
            $line['end_to_end_id'] = ($line['end_to_end_id'] ?? null) ?: 'E2E-'.strtoupper(Str::random(12));
            $line['instruction_id'] = ($line['instruction_id'] ?? null) ?: 'INSTR-'.strtoupper(Str::random(10));

            return $line;
        }, $lines);
    }

    /**
     * @param  list<array{path: string, code: string, params: array<string, string|int>}>  $issues
     *
     * @throws ValidationException
     */
    private function assertNoIssues(array $issues): void
    {
        if ($issues === []) {
            return;
        }

        $messages = [];

        foreach ($issues as $issue) {
            $messages[$issue['path']][] = __('treasury_flows.issue.'.$issue['code'], $issue['params']);
        }

        throw ValidationException::withMessages($messages);
    }
}
