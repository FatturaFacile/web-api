<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectRetainerLedgerEntry;
use Modules\Projects\Models\ProjectSalCertificate;
use Modules\Projects\Models\ProjectWipValuationRun;
use Modules\Projects\Queries\GetPrepaidRetainerBalanceQuery;
use Modules\Projects\Queries\GetProjectRetentionBalanceQuery;
use Modules\Projects\Queries\GetProjectSalCertificatesQuery;
use Modules\Projects\Queries\GetProjectUnbilledWipSummaryQuery;
use Modules\Projects\Queries\GetUnbilledTimeAndMaterialsQuery;
use Modules\Projects\Support\BillingStatuses;
use Modules\Projects\Support\Money;

/**
 * Read side of project billing (Mod 081): handlers of the queries.
 */
final readonly class ProjectBillingQueryService
{
    public function __construct(
        private ProjectBillingService $billing,
        private ProjectInvoiceReader $invoices,
        private ProjectTimeAndMaterialsBillingService $timeAndMaterials,
        private ProjectRetainerService $retainer,
        private ProjectRetentionService $retention,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function unbilledWipSummary(GetProjectUnbilledWipSummaryQuery $query): array
    {
        $project = Project::query()->findOrFail($query->projectId);
        $contract = $this->findContract($project);
        $openSal = ProjectSalCertificate::query()->where('project_id', $project->id)->whereIn('status', [BillingStatuses::SalDraft, BillingStatuses::SalPendingApproval, BillingStatuses::SalApproved])->get();
        $run = ProjectWipValuationRun::query()->where('project_id', $project->id)->orderByDesc('period_end_date')->first();
        $billed = $this->invoices->billedRevenue($project);

        return [
            'project_id' => (string) $project->id,
            'currency' => $contract->currency_code ?? $project->currency,
            'billing_model' => $contract?->billing_model,
            'contract_value' => Money::amount($contract->contract_total_value ?? $project->contract_value),
            'billed_to_date' => $billed,
            'draft_billing' => $this->invoices->draftBilling($project),
            'unbilled_time_and_materials' => $this->billing->unbilledValue($project),
            'unbilled_milestones' => $this->billing->unbilledMilestoneCount($project),
            'open_sal_certificates' => $openSal->count(),
            'open_sal_invoice_amount' => Money::amount(Money::add(...$openSal->pluck('invoice_amount')->map(static fn (mixed $v): string => (string) $v)->all())),
            'retention' => $contract !== null ? $this->retention->balance($contract) : null,
            'latest_wip_run' => $run === null ? null : [
                'period_end_date' => $run->period_end_date->toDateString(),
                'recognized_revenue_to_date' => Money::amount($run->recognized_revenue_to_date),
                'billed_to_date' => Money::amount($run->billed_to_date),
                'wip_asset_or_deferred_revenue_diff' => Money::amount($run->wip_asset_or_deferred_revenue_diff),
                'nature' => Money::isNegative($run->wip_asset_or_deferred_revenue_diff) ? 'contract_liability' : 'contract_asset',
            ],
        ];
    }

    /**
     * @return array{balance: array<string, mixed>, entries: Collection<int, ProjectRetainerLedgerEntry>}
     */
    public function prepaidRetainerBalance(GetPrepaidRetainerBalanceQuery $query): array
    {
        $project = Project::query()->findOrFail($query->projectId);
        $contract = $this->requireContract($project);

        return [
            'balance' => $this->retainer->balance($contract),
            'entries' => ProjectRetainerLedgerEntry::query()->where('billing_contract_id', $contract->id)->orderByDesc('entry_date')->orderByDesc('created_at')->limit(100)->get(),
        ];
    }

    /**
     * @return Collection<int, ProjectSalCertificate>
     */
    public function salCertificates(GetProjectSalCertificatesQuery $query): Collection
    {
        $project = Project::query()->findOrFail($query->projectId);

        return ProjectSalCertificate::query()->where('project_id', $project->id)->orderBy('sal_number')->orderBy('created_at')->get();
    }

    /**
     * @return array<string, string|null>
     */
    public function retentionBalance(GetProjectRetentionBalanceQuery $query): array
    {
        return $this->retention->balance($this->requireContract(Project::query()->findOrFail($query->projectId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function unbilledTimeAndMaterials(GetUnbilledTimeAndMaterialsQuery $query): array
    {
        $project = Project::query()->findOrFail($query->projectId);

        return $this->timeAndMaterials->preview($project, $this->requireContract($project), $query->upToDate);
    }

    public function findContract(Project $project): ?ProjectBillingContract
    {
        return ProjectBillingContract::query()->where('project_id', $project->id)->first();
    }

    private function requireContract(Project $project): ProjectBillingContract
    {
        return $this->findContract($project) ?? throw (new ModelNotFoundException)->setModel(ProjectBillingContract::class, [$project->id]);
    }
}
