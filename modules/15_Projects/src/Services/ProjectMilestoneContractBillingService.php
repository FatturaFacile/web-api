<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectMilestone;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Milestone billing of a fixed-price billing contract (Mod 081): invoices a completed milestone through the project
 * billing service and withholds the guarantee retention of the contract on the invoiced amount.
 */
final readonly class ProjectMilestoneContractBillingService
{
    public function __construct(
        private ProjectBillingService $billing,
        private ProjectRetentionService $retention,
    ) {}

    /**
     * @throws ConflictHttpException when the contract is not billable by milestones, or the milestone cannot be billed
     */
    public function bill(Project $project, ProjectBillingContract $contract, ProjectMilestone $milestone, string $taxRate, string $actorUserId): SalesInvoice
    {
        if (! $contract->isBillable()) {
            throw new ConflictHttpException(__('project_billing.errors.contract_not_billable'));
        }

        if (! $contract->model()->billsMilestones()) {
            throw new ConflictHttpException(__('project_billing.errors.model_not_allowed', ['model' => $contract->billing_model]));
        }

        return DB::transaction(function () use ($project, $contract, $milestone, $taxRate, $actorUserId): SalesInvoice {
            $invoice = $this->billing->billMilestone($project, $milestone, $taxRate, $actorUserId);
            $this->retention->withholdOnMilestone($project, $contract, $milestone, $invoice, $actorUserId);

            return $invoice;
        });
    }
}
