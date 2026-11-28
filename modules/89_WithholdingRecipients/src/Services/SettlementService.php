<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\WithholdingRecipients\Domain\Calculation\CashPeriodResolver;
use Modules\WithholdingRecipients\Domain\Calculation\CompensationResult;
use Modules\WithholdingRecipients\Domain\Calculation\F24LineBuilder;
use Modules\WithholdingRecipients\Domain\Enums\Jurisdiction;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\Enums\SettlementStatus;
use Modules\WithholdingRecipients\Exceptions\WithholdingRecipientException;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

/**
 * Posts the actual payment of a compensation (cash principle): runs the calculation of the regime on the payment date,
 * checks the INL gate of occasional work, freezes every figure and schedules the F24 (16th of the following month) and the
 * UniEmens flow.
 */
final class SettlementService
{
    public function __construct(
        private readonly CompensationEngine $engine,
        private readonly CashPeriodResolver $periods,
        private readonly F24LineBuilder $f24,
        private readonly InlNotificationService $inl,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data  gross_amount, payment_date, service_date, document_number, payment_reference, notes, performance_days, acquisition_expenses
     */
    public function post(RecipientProfile $profile, array $data, ?string $userId): RecipientSettlement
    {
        return DB::transaction(function () use ($profile, $data, $userId): RecipientSettlement {
            // The year's totals feed the thresholds, so concurrent postings of one recipient are serialised.
            $locked = RecipientProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            return $this->postLocked($locked, $data, $userId);
        });
    }

    /**
     * Posts several payments in one transaction, in order (each one counts in the year's totals of the next).
     *
     * @param  list<array<string, mixed>>  $items  each with recipient_profile_id plus the fields of post()
     * @return list<RecipientSettlement>
     */
    public function postBatch(array $items, ?string $userId): array
    {
        return DB::transaction(function () use ($items, $userId): array {
            $posted = [];

            foreach ($items as $item) {
                $profile = RecipientProfile::query()->whereKey((string) $item['recipient_profile_id'])->lockForUpdate()->firstOrFail();
                $posted[] = $this->postLocked($profile, $item, $userId);
            }

            return $posted;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postLocked(RecipientProfile $profile, array $data, ?string $userId): RecipientSettlement
    {
        if (! $profile->is_active) {
            throw new WithholdingRecipientException('The recipient is not active.', 'recipient_inactive');
        }

        $regime = $profile->regimeEnum();
        $paymentDate = CarbonImmutable::parse((string) $data['payment_date'])->startOfDay();
        $serviceDate = CarbonImmutable::parse((string) ($data['service_date'] ?? $data['payment_date']))->startOfDay();

        if ($serviceDate->greaterThan($paymentDate)) {
            throw new WithholdingRecipientException('The service cannot be later than the payment.', 'service_after_payment');
        }

        $notification = $regime === RecipientRegime::OccasionalWorker ? $this->inl->gate($profile, $serviceDate) : null;
        $input = $this->engine->input($data + ['payment_date' => $paymentDate->toDateString()], $profile);
        $result = $this->engine->run($input);

        if (! $result->netPayable->isPositive()) {
            throw new WithholdingRecipientException('The net payable amount is not positive.', 'net_not_positive');
        }

        $lines = $this->f24->lines($regime, $result, $paymentDate, $input->taxYear);
        $due = $regime->jurisdiction() === Jurisdiction::Italy && $lines !== [] ? $this->periods->f24DueDate($paymentDate) : null;

        $settlement = new RecipientSettlement($this->columns($result) + [
            'tenant_id' => (string) $this->tenantContext->getTenantId(),
            'recipient_profile_id' => $profile->id,
            'inl_notification_id' => $notification?->id,
            'regime' => $regime->value,
            'jurisdiction' => $regime->jurisdiction()->value,
            'document_number' => $data['document_number'] ?? $this->nextNumber($input->taxYear),
            'service_date' => $serviceDate->toDateString(),
            'payment_date' => $paymentDate->toDateString(),
            'tax_year' => $input->taxYear,
            'accrual_month' => $this->periods->accrualMonth($paymentDate),
            'status' => SettlementStatus::Posted->value,
            'cu_causal' => $profile->cu_causal ?? $result->cuCausal,
            'f24_due_date' => $due?->toDateString(),
            'f24_lines' => $lines,
            'uniemens_required' => $result->inpsTotal->isPositive(),
            'payment_reference' => $data['payment_reference'] ?? null,
            'breakdown' => ['thresholds' => $result->thresholds, 'notes' => $result->notes, 'effective_withholding_rate' => $result->effectiveRate()],
            'warnings' => $result->warnings,
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
        $settlement->save();

        return $settlement;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(CompensationResult $result): array
    {
        return [
            'currency' => $result->currency,
            'gross_amount' => $result->gross->toDecimal(),
            'flat_rate_deduction' => $result->flatRateDeduction->toDecimal(),
            'taxable_base' => $result->taxableBase->toDecimal(),
            'withholding_rate' => $result->withholdingRate,
            'irpef_withholding' => $result->withholding->toDecimal(),
            'inps_base' => $result->inpsBase->toDecimal(),
            'inps_total' => $result->inpsTotal->toDecimal(),
            'inps_worker' => $result->inpsWorker->toDecimal(),
            'inps_company' => $result->inpsCompany->toDecimal(),
            'stamp_duty' => $result->stampDuty->toDecimal(),
            'enasarco_base' => $result->enasarcoBase->toDecimal(),
            'enasarco_agent' => $result->enasarcoAgent->toDecimal(),
            'enasarco_company' => $result->enasarcoCompany->toDecimal(),
            'firr_accrual' => $result->firrAccrual->toDecimal(),
            'avs_base' => $result->avsBase->toDecimal(),
            'avs_employee' => $result->avsEmployee->toDecimal(),
            'avs_employer' => $result->avsEmployer->toDecimal(),
            'acquisition_expenses' => $result->acquisitionExpenses->toDecimal(),
            'source_tax' => $result->sourceTax->toDecimal(),
            'non_taxable_amount' => $result->nonTaxable->toDecimal(),
            'non_taxable_code' => $result->nonTaxableCode,
            'net_payable' => $result->netPayable->toDecimal(),
            'company_cost' => $result->companyCost->toDecimal(),
        ];
    }

    private function nextNumber(int $taxYear): string
    {
        $count = RecipientSettlement::query()->withTrashed()->where('tax_year', $taxYear)->count();

        return sprintf('CP-%d-%05d', $taxYear, $count + 1);
    }

    /**
     * Register of the settlements.
     *
     * @param  array<string, mixed>  $filters  recipient_profile_id, regime, jurisdiction, tax_year, accrual_month
     * @return Builder<RecipientSettlement>
     */
    public function register(array $filters): Builder
    {
        return RecipientSettlement::query()
            ->with('profile')
            ->when($filters['recipient_profile_id'] ?? null, static fn (Builder $q, string $id) => $q->where('recipient_profile_id', $id))
            ->when($filters['regime'] ?? null, static fn (Builder $q, string $regime) => $q->where('regime', $regime))
            ->when($filters['jurisdiction'] ?? null, static fn (Builder $q, string $j) => $q->where('jurisdiction', $j))
            ->when($filters['tax_year'] ?? null, static fn (Builder $q, int|string $year) => $q->where('tax_year', (int) $year))
            ->when($filters['accrual_month'] ?? null, static fn (Builder $q, string $month) => $q->where('accrual_month', $month))
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at');
    }
}
