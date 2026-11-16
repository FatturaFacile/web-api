<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Support\LeaseMoney;

/**
 * Writes the lease journals through the double-entry posting service of the general ledger: the fiscal period is the
 * open one containing the posting date, and a period that is missing or closed refuses the posting. Lines are in the
 * currency of the lease; the ledger converts them to the accounting currency of the tenant.
 */
final readonly class LeaseJournalPoster
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private FiscalPeriodResolver $periods,
        private LeaseAccountResolver $accounts,
    ) {}

    /**
     * Initial recognition: the right-of-use asset against the lease liability; the initial direct costs net of the
     * incentives received go through the settlement clearing account, which the payment of the costs clears.
     */
    public function postRecognition(LeaseContract $contract, string $liability, string $rouAsset, ?string $actorId): JournalEntry
    {
        $clearing = LeaseMoney::round(LeaseMoney::sub($rouAsset, $liability));
        $lines = [
            $this->line($contract, 'rou_asset', $rouAsset, '0', 'Right-of-use asset at commencement'),
            $this->line($contract, 'lease_liability', '0', $liability, 'Lease liability at commencement'),
        ];

        if (LeaseMoney::isPositive($clearing)) {
            $lines[] = $this->line($contract, 'lease_clearing', '0', $clearing, 'Initial direct costs net of incentives');
        } elseif (LeaseMoney::isPositive(LeaseMoney::abs($clearing))) {
            $lines[] = $this->line($contract, 'lease_clearing', LeaseMoney::abs($clearing), '0', 'Initial direct costs net of incentives');
        }

        return $this->post($contract, $contract->commencement_date->toDateString(), "Lease recognition {$contract->number}", 'lease_recognition', (string) $contract->id, "{$contract->number}/REC", $lines, $actorId);
    }

    /**
     * A period: interest on the liability, depreciation of the right-of-use asset and the payment due, which settles
     * the liability against the lessor payable (cleared by the payment through treasury or accounts payable).
     */
    public function postPeriod(LeaseContract $contract, string $postingDate, int $periodNumber, string $lineId, string $interest, string $depreciation, string $payment, ?string $actorId): JournalEntry
    {
        $lines = [];

        if (LeaseMoney::isPositive($interest)) {
            $lines[] = $this->line($contract, 'interest_expense', $interest, '0', "Interest on lease liability, period {$periodNumber}");
            $lines[] = $this->line($contract, 'lease_liability', '0', $interest, "Interest on lease liability, period {$periodNumber}");
        }

        if (LeaseMoney::isPositive($depreciation)) {
            $lines[] = $this->line($contract, 'depreciation_expense', $depreciation, '0', "Depreciation of right-of-use asset, period {$periodNumber}");
            $lines[] = $this->line($contract, 'accumulated_depreciation', '0', $depreciation, "Depreciation of right-of-use asset, period {$periodNumber}");
        }

        if (LeaseMoney::isPositive($payment)) {
            $lines[] = $this->line($contract, 'lease_liability', $payment, '0', "Lease payment due, period {$periodNumber}");
            $lines[] = $this->line($contract, 'lease_clearing', '0', $payment, "Lease payment due, period {$periodNumber}");
        }

        return $this->post($contract, $postingDate, "Lease {$contract->number} period {$periodNumber}", 'lease_period', $lineId, "{$contract->number}/{$periodNumber}", $lines, $actorId);
    }

    /**
     * Remeasurement: the change of the liability against the asset; what a reduction takes beyond the carrying amount
     * of the asset is a gain in the result of the period.
     */
    public function postRemeasurement(LeaseContract $contract, string $effectiveDate, string $versionId, int $versionNumber, string $liabilityAdjustment, string $rouAdjustment, string $result, ?string $actorId): ?JournalEntry
    {
        $lines = [];

        if (LeaseMoney::isPositive($liabilityAdjustment)) {
            $lines[] = $this->line($contract, 'rou_asset', $rouAdjustment, '0', 'Remeasurement of the right-of-use asset');
            $lines[] = $this->line($contract, 'lease_liability', '0', $liabilityAdjustment, 'Remeasurement of the lease liability');
        } elseif (LeaseMoney::isPositive(LeaseMoney::abs($liabilityAdjustment))) {
            $lines[] = $this->line($contract, 'lease_liability', LeaseMoney::abs($liabilityAdjustment), '0', 'Remeasurement of the lease liability');

            if (LeaseMoney::isPositive(LeaseMoney::abs($rouAdjustment))) {
                $lines[] = $this->line($contract, 'rou_asset', '0', LeaseMoney::abs($rouAdjustment), 'Remeasurement of the right-of-use asset');
            }

            if (LeaseMoney::isPositive($result)) {
                $lines[] = $this->line($contract, 'remeasurement_result', '0', $result, 'Remeasurement gain beyond the right-of-use asset');
            }
        }

        if ($lines === []) {
            return null;
        }

        return $this->post($contract, $effectiveDate, "Lease remeasurement {$contract->number} v{$versionNumber}", 'lease_remeasurement', $versionId, "{$contract->number}/V{$versionNumber}", $lines, $actorId);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException when the fiscal period or the ledger refuses the entry
     */
    private function post(LeaseContract $contract, string $date, string $description, string $sourceType, string $sourceId, string $documentNumber, array $lines, ?string $actorId): JournalEntry
    {
        $tenantId = (string) $contract->tenant_id;

        try {
            $period = $this->periods->forDate($tenantId, $date);

            return $this->postingService->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'LSE-'.strtoupper(Str::random(10)),
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
                'document_number' => $documentNumber,
                'posting_date' => $date,
                'document_date' => $date,
                'journal_type' => 'standard',
                'description' => $description,
                'lines' => $lines,
            ], $actorId);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['posting' => [$exception->getMessage()]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function line(LeaseContract $contract, string $role, string $debit, string $credit, string $description): array
    {
        $account = $this->accounts->forContract($contract, $role);
        $line = [
            'account_id' => $this->accountId($account),
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'description' => $description,
        ];

        if ($contract->currency !== TenantAccountingCurrency::of((string) $contract->tenant_id)) {
            $line['currency'] = $contract->currency;
        }

        return $line;
    }

    private function accountId(ChartOfAccount $account): string
    {
        return (string) $account->id;
    }
}
