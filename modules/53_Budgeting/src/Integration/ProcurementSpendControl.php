<?php

declare(strict_types=1);

namespace Modules\Budgeting\Integration;

use Illuminate\Support\Carbon;
use Modules\Budgeting\Domain\SpendRequest;
use Modules\Budgeting\Exceptions\SpendLockException;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Services\BaselineResolver;
use Modules\Budgeting\Services\CommitmentService;
use Modules\Budgeting\Services\SpendControlService;
use Modules\Procurement\Contracts\SpendControlInterface;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;

/**
 * The spend lock of the purchasing module: turns a requisition or an order into a spend request of the budget control
 * and a blocked decision into the validation error purchasing already knows. With the commitment sync it is the only
 * place of the budgeting module that knows the purchasing models.
 */
final readonly class ProcurementSpendControl implements SpendControlInterface
{
    public function __construct(
        private SpendControlService $control,
        private CommitmentService $commitments,
        private BaselineResolver $baselines,
    ) {}

    public function check(PurchaseOrder|PurchaseRequisition $document, string $stage): void
    {
        try {
            $this->control->enforce($this->requestFor($document, $stage));
        } catch (SpendLockException $exception) {
            throw new ProcurementRuleViolation($exception->getMessage(), 'budget');
        }
    }

    public function release(PurchaseOrder|PurchaseRequisition $document): void
    {
        $isOrder = $document instanceof PurchaseOrder;
        $type = $isOrder ? BudgetCommitment::PurchaseOrder : BudgetCommitment::Requisition;
        $this->commitments->release((string) $document->tenant_id, $type, (string) $document->id, 'cancelled', $this->userId());

        if ($isOrder && $document->purchase_requisition_id !== null) {
            $this->commitments->reconcileRequisition((string) $document->tenant_id, (string) $document->purchase_requisition_id);
        }
    }

    /**
     * The net amount of a document in the currency of the budget.
     */
    public function netAmount(PurchaseOrder|PurchaseRequisition $document): string
    {
        $net = $document instanceof PurchaseOrder
            ? ($document->total_net ?? $document->net_amount ?? bcsub((string) ($document->total_amount ?? '0'), (string) ($document->total_tax ?? $document->tax_amount ?? '0'), 2))
            : ($document->total_estimated_amount ?? '0');

        return $this->convert(bcadd((string) $net, '0', 2), $document, $this->documentDate($document));
    }

    public function documentDate(PurchaseOrder|PurchaseRequisition $document): Carbon
    {
        $date = $document instanceof PurchaseOrder ? ($document->date ?? $document->order_date) : $document->required_by_date;

        return $date !== null ? Carbon::parse($date) : now();
    }

    /**
     * Converts into the budget currency with the exchange rate of the order; a document in another currency without a
     * rate (a requisition) is taken as it is.
     */
    public function convert(string $amount, PurchaseOrder|PurchaseRequisition $document, Carbon $date): string
    {
        $budgetCurrency = $this->baselines->currency((string) $document->tenant_id, $this->baselines->forDate((string) $document->tenant_id, $date));

        if ($document->currency === $budgetCurrency || ! $document instanceof PurchaseOrder || (float) $document->exchange_rate <= 0.0) {
            return $amount;
        }

        return bcround(bcmul($amount, (string) $document->exchange_rate, 8), 2);
    }

    private function requestFor(PurchaseOrder|PurchaseRequisition $document, string $stage): SpendRequest
    {
        $isOrder = $document instanceof PurchaseOrder;

        return new SpendRequest(
            tenantId: (string) $document->tenant_id,
            sourceType: $isOrder ? BudgetCommitment::PurchaseOrder : BudgetCommitment::Requisition,
            sourceId: (string) $document->id,
            sourceNumber: $isOrder ? (string) ($document->po_number ?? $document->order_number) : (string) $document->requisition_number,
            stage: $stage,
            amount: $this->netAmount($document),
            accountId: null,
            costCenterId: $document->cost_center_id !== null ? (string) $document->cost_center_id : null,
            projectId: $document->project_id !== null ? (string) $document->project_id : null,
            date: $this->documentDate($document),
            userId: $this->userId(),
            replacesRequisitionId: $isOrder && $document->purchase_requisition_id !== null ? (string) $document->purchase_requisition_id : null,
        );
    }

    private function userId(): ?string
    {
        $id = request()->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
