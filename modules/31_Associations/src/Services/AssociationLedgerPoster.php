<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Support\Money;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\LedgerAccountResolver;

/**
 * Posts the movements of the association that are not sales documents (reimbursements, donations, campaign expenses)
 * through the posting service of the general ledger, in the open period of the day. The counterpart is the cash or the
 * bank account of the ledger; the cost centre and the project travel on the line, so sections and campaigns can be
 * reported separately.
 */
final class AssociationLedgerPoster
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $posting,
        private readonly FiscalPeriodResolver $periods,
        private readonly LedgerAccountResolver $accounts,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * An outflow: the expense account in debit, cash or bank in credit.
     *
     * @param  array{cost_center_id?: ?string, project_id?: ?string}  $dimensions
     */
    public function postOutflow(string $expenseAccountId, string $amount, CarbonImmutable $date, string $paymentMethod, string $description, string $sourceType, string $sourceId, array $dimensions, ?string $actorId): JournalEntry
    {
        return $this->post($expenseAccountId, $amount, $date, $paymentMethod, $description, $sourceType, $sourceId, $dimensions, $actorId, false);
    }

    /**
     * An inflow: cash or bank in debit, the revenue account in credit.
     *
     * @param  array{cost_center_id?: ?string, project_id?: ?string}  $dimensions
     */
    public function postInflow(string $revenueAccountId, string $amount, CarbonImmutable $date, string $paymentMethod, string $description, string $sourceType, string $sourceId, array $dimensions, ?string $actorId): JournalEntry
    {
        return $this->post($revenueAccountId, $amount, $date, $paymentMethod, $description, $sourceType, $sourceId, $dimensions, $actorId, true);
    }

    /**
     * @param  array{cost_center_id?: ?string, project_id?: ?string}  $dimensions
     */
    private function post(string $accountId, string $amount, CarbonImmutable $date, string $paymentMethod, string $description, string $sourceType, string $sourceId, array $dimensions, ?string $actorId, bool $inflow): JournalEntry
    {
        if (! Money::isPositive($amount)) {
            throw new AssociationException(__('association_governance.amount_not_positive'));
        }

        $tenantId = (string) $this->tenantContext->getTenantId();
        $liquidity = $paymentMethod === 'cash' ? $this->accounts->cash($tenantId) : $this->accounts->bank($tenantId);
        $line = [
            'account_id' => $accountId,
            'cost_center_id' => $dimensions['cost_center_id'] ?? null,
            'project_id' => $dimensions['project_id'] ?? null,
            'description' => $description,
        ];
        $counterpart = ['account_id' => $liquidity->id, 'description' => $description];

        $lines = $inflow
            ? [[...$counterpart, 'debit_amount' => $amount, 'credit_amount' => '0'], [...$line, 'debit_amount' => '0', 'credit_amount' => $amount]]
            : [[...$line, 'debit_amount' => $amount, 'credit_amount' => '0'], [...$counterpart, 'debit_amount' => '0', 'credit_amount' => $amount]];

        return $this->posting->createAndPost([
            'tenant_id' => $tenantId,
            'fiscal_period_id' => $this->periods->forDate($tenantId, $date)->id,
            'posting_date' => $date->toDateString(),
            'description' => mb_substr($description, 0, 255),
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'lines' => $lines,
        ], $actorId);
    }
}
